<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Documents\Enums\ProcessingStatus;
use App\Copilot\Documents\Services\DocumentProcessingService;
use App\Copilot\Identity\PocUser;
use App\Copilot\Support\PocStateService;
use App\Copilot\Workflow\Services\DocumentWorkflowService;
use App\Http\Requests\Copilot\UploadDocumentRequest;
use App\Models\Copilot\OriginalDocument;
use App\Models\Copilot\SubDocument;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController
{
    public function store(UploadDocumentRequest $request, DocumentProcessingService $documents, DocumentWorkflowService $workflow, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validated();
        $actor = $this->actor($request);

        $original = $documents->storeUpload($validated['document'], $actor);
        $audit->record(
            'poc-document-upload-accepted',
            $actor,
            'original_document',
            (string) $original->id,
            ['filename' => $original->original_filename],
            $request,
        );

        $workflow->start($original, $actor, $request);

        return response()->json([
            'message' => 'Documento caricato. Workflow documentale avviato.',
            'streamUrl' => route('api.v1.documents.stream', $original),
        ], 202);
    }

    public function stream(Request $request, OriginalDocument $originalDocument, PocStateService $state): StreamedResponse
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

            $sentDocumentIds = [];
            $startedAt = time();
            $timeoutSeconds = 300;

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

    public function destroy(Request $request, SubDocument $subDocument, AuditLogger $audit, PocStateService $state): JsonResponse
    {
        $original = $subDocument->originalDocument;
        $actor = $this->actor($request);

        if ($original) {
            $this->authorizeOriginalDocument($original, $actor);
        }

        $disk = config('poc.documents.storage_disk', config('filesystems.default', 'local'));
        $subFilePath = $subDocument->file_path;

        $subDocument->delete();
        Storage::disk($disk)->delete($subFilePath);
        $audit->record(
            'poc-sub-document-deleted',
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

    public function preview(Request $request, SubDocument $subDocument): StreamedResponse
    {
        if ($subDocument->originalDocument) {
            $this->authorizeOriginalDocument($subDocument->originalDocument, $this->actor($request));
        }

        $disk = Storage::disk(config('poc.documents.storage_disk', config('filesystems.default', 'local')));

        abort_unless($disk->exists($subDocument->file_path), 404);

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

    private function actor(Request $request): PocUser
    {
        $actor = $request->user();

        if (! $actor instanceof PocUser) {
            throw new \RuntimeException('PoC identity middleware did not provide a structured user.');
        }

        return $actor;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeOriginalDocument(OriginalDocument $document, PocUser $actor): void
    {
        if ($document->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Documento non autorizzato per il tenant corrente.');
        }
    }
}
