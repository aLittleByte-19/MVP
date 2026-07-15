<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Documents\Enums\ProcessingStatus;
use App\Copilot\Documents\Enums\ReviewStatus;
use App\Copilot\Documents\Services\DocumentProcessingService;
use App\Copilot\Identity\MvpUser;
use App\Copilot\Support\MvpStateService;
use App\Copilot\Workflow\Services\DocumentWorkflowService;
use App\Http\Requests\Copilot\UpdateExtractedDataRequest;
use App\Http\Requests\Copilot\UploadDocumentRequest;
use App\Models\Copilot\ExtractedData;
use App\Models\Copilot\OriginalDocument;
use App\Models\Copilot\SubDocument;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController
{
    public function store(UploadDocumentRequest $request, DocumentProcessingService $documents, DocumentWorkflowService $workflow, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validated();
        $actor = $this->actor($request);

        $original = $documents->storeUpload($validated['document'], $actor);
        $audit->record(
            'mvp-document-upload-accepted',
            $actor,
            'original_document',
            (string) $original->id,
            ['filename' => $original->original_filename],
            $request,
        );

        $workflow->start($original, $actor, $request);

        return response()->json([
            'message' => 'Documento caricato. Workflow documentale avviato.',
            // URL relativo: la SPA e' servita in HTTPS dietro Traefik, che termina il
            // TLS e inoltra in HTTP. Un URL assoluto verrebbe generato con schema
            // "http://" e bloccato dal browser come mixed-content / CSP connect-src.
            'streamUrl' => route('api.v1.documents.stream', $original, false),
        ], 202);
    }

    public function stream(Request $request, OriginalDocument $originalDocument, MvpStateService $state): StreamedResponse
    {
        $actor = $this->actor($request);
        $this->authorizeOriginalDocument($originalDocument, $actor);

        return response()->stream(function () use ($originalDocument, $actor, $state): void {
            if (app()->runningUnitTests()) {
                return;
            }

            set_time_limit(0);

            $send = function (string $event, array $data): void {
                echo "event: {$event}\ndata: ".json_encode($data)."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            // Commento SSE: ignorato dal browser ma mantiene viva la connessione
            // quando non ci sono novita' (evita chiusure da idle-timeout dei proxy).
            $heartbeat = function (): void {
                echo ": keepalive\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            $sentDocumentIds = [];
            $startedAt = time();
            $timeoutSeconds = 300;
            $lastSignature = null;

            while (! connection_aborted()) {
                $freshDocument = OriginalDocument::query()
                    ->with(['subDocuments' => fn ($query) => $query
                        ->with(['originalDocument', 'extractedData'])
                        ->orderBy('id')])
                    ->find($originalDocument->id);

                if (! $freshDocument) {
                    $send('error', ['message' => 'Documento non trovato.']);

                    return;
                }

                foreach ($freshDocument->subDocuments as $subDocument) {
                    if (in_array($subDocument->id, $sentDocumentIds, true) || ! $subDocument->extractedData) {
                        continue;
                    }

                    $sentDocumentIds[] = $subDocument->id;
                    $send('document', $state->document($subDocument));
                }

                // Avanzamento a step per la barra di progressione della SPA: stato
                // del workflow + numero di sotto-documenti gia' estratti. Si emette
                // solo quando qualcosa cambia; altrimenti un heartbeat tiene viva la
                // connessione senza generare rumore.
                $signature = $freshDocument->processing_status->value.':'.count($sentDocumentIds);

                if ($signature !== $lastSignature) {
                    $send('progress', [
                        'status' => $freshDocument->processing_status->value,
                        'subDocuments' => count($sentDocumentIds),
                    ]);
                    $lastSignature = $signature;
                } else {
                    $heartbeat();
                }

                if ($freshDocument->processing_status === ProcessingStatus::Completed) {
                    $send('done', ['state' => $state->forActor($actor)]);

                    return;
                }

                if ($freshDocument->processing_status === ProcessingStatus::Failed) {
                    $send('error', ['message' => $freshDocument->error_message ?: 'Analisi documento non disponibile.']);

                    return;
                }

                if (time() - $startedAt >= $timeoutSeconds) {
                    $send('error', ['message' => 'Timeout elaborazione.']);

                    return;
                }

                if (app()->runningUnitTests()) {
                    return;
                }

                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function destroy(Request $request, SubDocument $subDocument, AuditLogger $audit, MvpStateService $state): JsonResponse
    {
        $original = $subDocument->originalDocument;
        $actor = $this->actor($request);

        if ($original) {
            $this->authorizeOriginalDocument($original, $actor);
        }

        $disk = config('mvp.documents.storage_disk', config('filesystems.default', 'local'));
        $subFilePath = $subDocument->file_path;

        $subDocument->delete();
        Storage::disk($disk)->delete($subFilePath);
        $audit->record(
            'mvp-sub-document-deleted',
            $actor,
            'sub_document',
            (string) $subDocument->id,
            ['original_document_id' => $original?->id],
            $request,
        );

        if ($original && $original->subDocuments()->doesntExist()) {
            $originalFilePath = $original->file_path;
            $original->delete();
            Storage::disk($disk)->delete($originalFilePath);
        }

        return response()->json([
            'message' => 'Documento eliminato.',
            'state' => $state->forActor($actor),
        ]);
    }

    public function updateExtractedData(
        UpdateExtractedDataRequest $request,
        SubDocument $subDocument,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->authorizeSubDocument($subDocument, $actor);

        $validated = $request->validated();
        $existing = $subDocument->extractedData;
        $updates = $this->extractedDataUpdates($validated);

        if ($updates !== [] || ! $existing) {
            ExtractedData::updateOrCreate(
                ['sub_document_id' => $subDocument->id],
                $updates,
            );
        }

        $reviewStatus = ($validated['markAsValidated'] ?? false)
            ? ReviewStatus::ManuallyValidated
            : ReviewStatus::NeedsReview;
        $subDocument->update([
            'review_status' => $reviewStatus,
            'error_message' => null,
        ]);
        $audit->record(
            'mvp-sub-document-extracted-data-corrected',
            $actor,
            'sub_document',
            (string) $subDocument->id,
            [
                'changed_fields' => array_keys($updates),
                'review_status' => $reviewStatus->value,
            ],
            $request,
        );

        return response()->json([
            'message' => $reviewStatus === ReviewStatus::ManuallyValidated
                ? 'Dati estratti corretti e validati manualmente.'
                : 'Dati estratti aggiornati.',
            'document' => $state->document($subDocument->fresh(['originalDocument', 'extractedData'])),
            'state' => $state->forActor($actor),
        ]);
    }

    public function markReviewed(Request $request, SubDocument $subDocument, AuditLogger $audit, MvpStateService $state): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeSubDocument($subDocument, $actor);

        if (! $subDocument->extractedData) {
            throw ValidationException::withMessages([
                'subDocument' => ['Correggi i dati estratti prima di validare manualmente il sotto-documento.'],
            ]);
        }

        $subDocument->update([
            'review_status' => ReviewStatus::ManuallyValidated,
            'error_message' => null,
        ]);
        $audit->record(
            'mvp-sub-document-manually-validated',
            $actor,
            'sub_document',
            (string) $subDocument->id,
            ['review_status' => ReviewStatus::ManuallyValidated->value],
            $request,
        );

        return response()->json([
            'message' => 'Sotto-documento validato manualmente.',
            'document' => $state->document($subDocument->fresh(['originalDocument', 'extractedData'])),
            'state' => $state->forActor($actor),
        ]);
    }

    public function preview(Request $request, SubDocument $subDocument): StreamedResponse
    {
        if ($subDocument->originalDocument) {
            $this->authorizeOriginalDocument($subDocument->originalDocument, $this->actor($request));
        }

        $disk = Storage::disk(config('mvp.documents.storage_disk', config('filesystems.default', 'local')));

        try {
            abort_unless($disk->exists($subDocument->file_path), 404);
        } catch (FilesystemException $exception) {
            report($exception);

            abort(503, 'Storage documenti non raggiungibile.');
        }

        $filename = $subDocument->originalDocument?->original_filename ?: 'documento.pdf';

        return response()->stream(function () use ($disk, $subDocument): void {
            $stream = $disk->readStream($subDocument->file_path);

            if (! is_resource($stream)) {
                return;
            }

            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $filename).'"',
        ]);
    }

    private function actor(Request $request): MvpUser
    {
        $actor = $request->user();

        if (! $actor instanceof MvpUser) {
            throw new \RuntimeException('MVP identity middleware did not provide a structured user.');
        }

        return $actor;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeOriginalDocument(OriginalDocument $document, MvpUser $actor): void
    {
        if ($document->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Documento non autorizzato per il tenant corrente.');
        }
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeSubDocument(SubDocument $subDocument, MvpUser $actor): void
    {
        if (! $subDocument->originalDocument || $subDocument->originalDocument->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Documento non autorizzato per il tenant corrente.');
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function extractedDataUpdates(array $validated): array
    {
        $map = [
            'employeeFirstName' => 'employee_first_name',
            'employeeLastName' => 'employee_last_name',
            'companyName' => 'company_name',
            'documentDate' => 'document_date',
            'documentType' => 'document_type',
            'description' => 'description',
            'confidenceScore' => 'confidence_score',
        ];
        $updates = [];

        foreach ($map as $requestKey => $column) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $value = $validated[$requestKey];
            $updates[$column] = is_string($value) ? trim($value) : $value;
        }

        return $updates;
    }
}
