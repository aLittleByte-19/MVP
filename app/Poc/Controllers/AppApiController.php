<?php

namespace App\Poc\Controllers;

use App\Poc\Enums\CommunicationStatus;
use App\Poc\Enums\ProcessingStatus;
use App\Poc\Exceptions\AiServiceException;
use App\Poc\Jobs\ProcessOriginalDocumentJob;
use App\Poc\Models\Communication;
use App\Poc\Models\ExtractedData;
use App\Poc\Models\OriginalDocument;
use App\Poc\Models\SubDocument;
use App\Poc\Requests\GenerateCommunicationRequest;
use App\Poc\Requests\UploadDocumentRequest;
use App\Poc\Security\PocUser;
use App\Poc\Services\AuditLogger;
use App\Poc\Services\BedrockService;
use App\Poc\Services\DocumentProcessingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AppApiController
{
    public function state(Request $request): JsonResponse
    {
        return response()->json($this->stateData($this->actor($request)));
    }

    /**
     * @throws AiServiceException
     */
    public function generateCommunication(GenerateCommunicationRequest $request, BedrockService $bedrock, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validated();
        $actor = $this->actor($request);

        try {
            $generated = $bedrock->generateCommunication(
                $validated['prompt'],
                $validated['tone'],
                $validated['style'],
            );
        } catch (\Throwable $e) {
            Log::warning('PoC communication generation failed', ['message' => $e->getMessage()]);

            throw new AiServiceException(
                BedrockService::formatUserError($e, 'Generazione non disponibile. Verifica la configurazione AI.'),
                502,
                $e
            );
        }

        $communication = Communication::create([
            'tenant_id' => $actor->tenantId,
            'created_by' => $actor->id,
            'prompt' => $validated['prompt'],
            'tone' => $validated['tone'],
            'style' => $validated['style'],
            'generated_title' => $generated['title'],
            'generated_body' => $generated['body'],
            'status' => CommunicationStatus::Draft,
        ]);
        $audit->record(
            'poc-communication-generated',
            $actor,
            'communication',
            (string) $communication->id,
            ['tone' => $communication->tone, 'style' => $communication->style],
            $request,
        );

        return response()->json([
            'message' => 'Bozza generata correttamente.',
            'communication' => $this->serializeCommunication($communication),
            'state' => $this->stateData($actor),
        ], 201);
    }

    public function runDocumentOcr(UploadDocumentRequest $request, DocumentProcessingService $documents, AuditLogger $audit): JsonResponse
    {
        $validated = $request->validated();
        $actor = $this->actor($request);

        $original = $documents->storeUpload($validated['document'], $actor);
        $original->update(['processing_status' => ProcessingStatus::Processing]);
        $audit->record(
            'poc-document-upload-accepted',
            $actor,
            'original_document',
            (string) $original->id,
            ['filename' => $original->original_filename],
            $request,
        );

        ProcessOriginalDocumentJob::dispatch($original);

        return response()->json([
            'message' => 'Documento caricato. Elaborazione avviata in coda.',
            'streamUrl' => route('api.v1.documents.stream', $original),
        ], 202);
    }

    /**
     * Poll the database and stream sub-documents via SSE as the queue job commits them one at a time.
     */
    public function streamDocumentProcessing(Request $request, OriginalDocument $originalDocument): StreamedResponse
    {
        $actor = $this->actor($request);
        $this->authorizeOriginalDocument($originalDocument, $actor);

        return response()->stream(function () use ($originalDocument, $actor): void {
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
                    $send('document', $this->serializeDocument($subDocument));
                }

                if ($freshDocument->processing_status === ProcessingStatus::Completed) {
                    $send('done', ['state' => $this->stateData($actor)]);

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

    public function deleteSubDocument(Request $request, SubDocument $subDocument, AuditLogger $audit): JsonResponse
    {
        $original = $subDocument->originalDocument;
        $actor = $this->actor($request);

        if ($original) {
            $this->authorizeOriginalDocument($original, $actor);
        }

        $disk = config('filesystems.default', 'local');
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
            'state' => $this->stateData($actor),
        ]);
    }

    public function previewSubDocument(Request $request, SubDocument $subDocument): StreamedResponse
    {
        if ($subDocument->originalDocument) {
            $this->authorizeOriginalDocument($subDocument->originalDocument, $this->actor($request));
        }

        $disk = Storage::disk(config('filesystems.default', 'local'));

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

    /**
     * Payload consumed by the initial dashboard bootstrap and SSE completion event.
     *
     * @return array<string, mixed>
     */
    private function stateData(PocUser $actor): array
    {
        return [
            'assistant' => $this->getAssistantState($actor),
            'copilot' => $this->getCopilotState($actor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getAssistantState(PocUser $actor): array
    {
        $baseQuery = Communication::query()->where('tenant_id', $actor->tenantId);
        $total = (clone $baseQuery)->count();
        $drafts = (clone $baseQuery)->where('status', CommunicationStatus::Draft)->count();
        $history = (clone $baseQuery)->latest()->limit(10)->get();

        return [
            'metrics' => [
                ['value' => $total, 'label' => 'Contenuti generati'],
                ['value' => $drafts, 'label' => 'Bozze generate'],
            ],
            'history' => $history->map(fn ($c) => $this->serializeCommunication($c))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getCopilotState(PocUser $actor): array
    {
        $documents = SubDocument::query()
            ->with(['originalDocument', 'extractedData'])
            ->whereHas('originalDocument', fn ($query) => $query->where('tenant_id', $actor->tenantId))
            ->latest()
            ->limit(40)
            ->get();

        $originalCount = OriginalDocument::query()->where('tenant_id', $actor->tenantId)->count();
        $confidenceThreshold = (int) config('services.bedrock.poc_confidence_threshold', 80);

        return [
            'metrics' => [
                ['value' => $originalCount, 'label' => 'Documenti analizzati'],
                ['value' => SubDocument::query()->whereHas('originalDocument', fn ($query) => $query->where('tenant_id', $actor->tenantId))->count(), 'label' => 'Sotto-documenti rilevati'],
                ['value' => ExtractedData::query()->whereHas('subDocument.originalDocument', fn ($query) => $query->where('tenant_id', $actor->tenantId))->where('confidence_score', '>=', $confidenceThreshold)->count(), 'label' => 'Campi con confidenza'],
                ['value' => ExtractedData::query()->whereHas('subDocument.originalDocument', fn ($query) => $query->where('tenant_id', $actor->tenantId))->where(fn ($q) => $q->where('confidence_score', '<', $confidenceThreshold)->orWhereNull('confidence_score'))->count(), 'label' => 'Da verificare'],
            ],
            'documents' => $documents->map(fn ($d) => $this->serializeDocument($d))->values()->all(),
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function serializeCommunication(Communication $communication): array
    {
        return [
            'id' => $communication->id,
            'prompt' => $communication->prompt,
            'tone' => $communication->tone,
            'style' => $communication->style,
            'title' => $communication->generated_title,
            'body' => $communication->generated_body,
            'status' => $communication->status->label(),
            'createdAt' => $communication->created_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDocument(SubDocument $subDocument): array
    {
        $original = $subDocument->originalDocument;
        $data = $subDocument->extractedData;
        $employee = trim(implode(' ', array_filter([
            $data?->employee_first_name,
            $data?->employee_last_name,
        ])));
        $confidence = $data?->confidence_score;
        $pages = max(1, ((int) $subDocument->end_page - (int) $subDocument->start_page) + 1);
        $previewLines = [
            'Split iniziale: pagine '.$subDocument->start_page.'-'.$subDocument->end_page.'.',
            'File originale: '.($original?->original_filename ?: 'Non disponibile').'.',
            'Campi rilevati dal servizio AI configurato.',
        ];

        if ($subDocument->error_message) {
            $previewLines[] = 'Errore estrazione: '.$subDocument->error_message;
        }

        return [
            'id' => 'sub-'.$subDocument->id,
            'title' => $data?->document_type ?: $original?->original_filename,
            'employee' => $employee !== '' ? $employee : null,
            'company' => $data?->company_name,
            'file' => $original?->original_filename,
            'date' => $data?->document_date?->format('d/m/Y'),
            'pages' => $pages,
            'type' => $data?->document_type,
            'description' => $data?->description,
            'confidence' => $confidence,
            'error' => $subDocument->error_message,
            'previewUrl' => route('api.v1.documents.preview', ['subDocument' => $subDocument->id]),
            'previewLines' => $previewLines,
        ];
    }
}
