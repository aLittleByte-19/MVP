<?php

namespace App\Poc\Controllers;

use App\Poc\Enums\CommunicationStatus;
use App\Poc\Enums\ProcessingStatus;
use App\Poc\Jobs\ProcessOriginalDocumentJob;
use App\Poc\Models\Communication;
use App\Poc\Models\ExtractedData;
use App\Poc\Models\OriginalDocument;
use App\Poc\Models\SubDocument;
use App\Poc\Services\BedrockService;
use App\Poc\Services\DocumentProcessingService;
use App\Poc\Requests\GenerateCommunicationRequest;
use App\Poc\Requests\UploadDocumentRequest;
use App\Poc\Exceptions\AiServiceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API Controller for the PoC application.
 */
class AppApiController
{
    /**
     * Get the current state of the application.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function state(): JsonResponse
    {
        return response()->json($this->stateData());
    }

    /**
     * Generate a communication using AI.
     *
     * @param  \App\Poc\Requests\GenerateCommunicationRequest  $request
     * @param  \App\Poc\Services\BedrockService  $bedrock
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \App\Poc\Exceptions\AiServiceException
     */
    public function generateCommunication(GenerateCommunicationRequest $request, BedrockService $bedrock): JsonResponse
    {
        $validated = $request->validated();

        try {
            $generated = $bedrock->generateCommunication(
                $validated['prompt'],
                $validated['tone'],
                $validated['style'],
            );
        } catch (\Throwable $e) {
            Log::warning('PoC communication generation failed', ['message' => $e->getMessage()]);

            throw new AiServiceException(
                $this->formatAiError($e, 'Generazione non disponibile. Verifica la configurazione AI.'),
                502,
                $e
            );
        }

        $communication = Communication::create([
            'prompt' => $validated['prompt'],
            'tone' => $validated['tone'],
            'style' => $validated['style'],
            'generated_title' => $generated['title'],
            'generated_body' => $generated['body'],
            'status' => CommunicationStatus::Draft,
        ]);

        return response()->json([
            'message' => 'Bozza generata correttamente.',
            'communication' => $this->serializeCommunication($communication),
            'state' => $this->stateData(),
        ], 201);
    }

    /**
     * Run OCR and processing on an uploaded document.
     *
     * @param  \App\Poc\Requests\UploadDocumentRequest  $request
     * @param  \App\Poc\Services\DocumentProcessingService  $documents
     * @return \Illuminate\Http\JsonResponse
     */
    public function runDocumentOcr(UploadDocumentRequest $request, DocumentProcessingService $documents): JsonResponse
    {
        $validated = $request->validated();

        $original = $documents->storeUpload($validated['document']);

        ProcessOriginalDocumentJob::dispatch($original);

        return response()->json([
            'message' => 'Documento caricato. Elaborazione avviata in coda.',
            'streamUrl' => route('poc.api.documents.stream', $original),
        ], 202);
    }

    /**
     * Stream the processing status of a document using Server-Sent Events.
     *
     * @param  \App\Poc\Models\OriginalDocument  $originalDocument
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function streamDocumentProcessing(OriginalDocument $originalDocument): StreamedResponse
    {
        return response()->stream(function () use ($originalDocument): void {
            set_time_limit(0);

            $send = function (string $event, array $data): void {
                echo "event: {$event}\ndata: " . json_encode($data) . "\n\n";
                if (ob_get_level()) ob_flush();
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
                    $send('done', ['state' => $this->stateData()]);
                    return;
                }

                if ($freshDocument->processing_status === ProcessingStatus::Failed) {
                    $send('error', ['message' => 'Analisi documento non disponibile.']);
                    return;
                }

                if (time() - $startedAt >= $timeoutSeconds) {
                    $send('error', ['message' => 'Timeout elaborazione.']);
                    return;
                }

                // In test environment, don't loop/sleep if we're using sync queue
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

    /**
     * Delete a sub-document.
     *
     * @param  \App\Poc\Models\SubDocument  $subDocument
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteSubDocument(SubDocument $subDocument): JsonResponse
    {
        $original = $subDocument->originalDocument;

        Storage::disk($this->documentDisk())->delete($subDocument->file_path);
        $subDocument->delete();

        if ($original && $original->subDocuments()->doesntExist()) {
            Storage::disk($this->documentDisk())->delete($original->file_path);
            $original->delete();
        }

        return response()->json([
            'message' => 'Documento eliminato.',
            'state' => $this->stateData(),
        ]);
    }

    /**
     * Preview a sub-document PDF.
     *
     * @param  \App\Poc\Models\SubDocument  $subDocument
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function previewSubDocument(SubDocument $subDocument)
    {
        $disk = Storage::disk($this->documentDisk());

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
     * Get the current state data.
     *
     * @return array<string, mixed>
     */
    private function stateData(): array
    {
        return [
            'assistant' => $this->getAssistantState(),
            'copilot' => $this->getCopilotState(),
        ];
    }

    /**
     * Get the state for the assistant feature.
     *
     * @return array<string, mixed>
     */
    private function getAssistantState(): array
    {
        $all = Communication::query()->latest()->get();

        return [
            'metrics' => [
                [
                    'value' => $all->where('status', CommunicationStatus::Approved)->count(),
                    'label' => 'Contenuti generati',
                ],
                [
                    'value' => $all->where('status', CommunicationStatus::Draft)->count(),
                    'label' => 'Bozze generate',
                ],
            ],
            'history' => $all->take(10)->map(fn ($c) => $this->serializeCommunication($c))->values()->all(),
        ];
    }

    /**
     * Get the state for the copilot feature.
     *
     * @return array<string, mixed>
     */
    private function getCopilotState(): array
    {
        $documents = SubDocument::query()
            ->with(['originalDocument', 'extractedData'])
            ->latest()
            ->limit(40)
            ->get();

        $originalCount = OriginalDocument::query()->count();
        $confidenceThreshold = (int) env('POC_CONFIDENCE_THRESHOLD', 80);

        return [
            'metrics' => [
                ['value' => $originalCount, 'label' => 'Documenti analizzati'],
                ['value' => SubDocument::query()->count(), 'label' => 'Sotto-documenti rilevati'],
                ['value' => ExtractedData::query()->where('confidence_score', '>=', $confidenceThreshold)->count(), 'label' => 'Campi con confidenza'],
                ['value' => ExtractedData::query()->where(fn($q) => $q->where('confidence_score', '<', $confidenceThreshold)->orWhereNull('confidence_score'))->count(), 'label' => 'Da verificare'],
            ],
            'documents' => $documents->map(fn ($d) => $this->serializeDocument($d))->values()->all(),
        ];
    }

    /**
     * Serialize a communication model.
     *
     * @param  \App\Poc\Models\Communication  $communication
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
     * Serialize a sub-document model.
     *
     * @param  \App\Poc\Models\SubDocument  $subDocument
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
            'previewUrl' => route('poc.documents.preview', ['subDocument' => $subDocument->id]),
            'previewLines' => [
                'Split iniziale: pagine '.$subDocument->start_page.'-'.$subDocument->end_page.'.',
                'File originale: '.($original?->original_filename ?: 'Non disponibile').'.',
                'Campi OCR rilevati dal servizio AI configurato o dal fallback PoC.',
            ],
        ];
    }

    /**
     * Format an AI error message.
     *
     * @param  \Throwable  $exception
     * @param  string  $fallback
     * @return string
     */
    private function formatAiError(\Throwable $exception, string $fallback): string
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'expiredtoken')) {
            return 'Le credenziali AWS temporanee sono scadute. Aggiorna AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY e AWS_SESSION_TOKEN, poi ricarica la configurazione.';
        }

        if (str_contains($message, 'model access is denied')) {
            return 'Il modello Bedrock configurato non è accessibile con queste credenziali. Usa un modello abilitato, ad esempio amazon.nova-lite-v1:0.';
        }

        if (str_contains($message, 'on-demand throughput') || str_contains($message, 'inference profile')) {
            return 'Il modello Bedrock richiede un inference profile. Aggiorna BEDROCK_MODEL_ID con un profilo valido oppure usa amazon.nova-lite-v1:0.';
        }

        return $fallback;
    }

    /**
     * Get the configured document disk.
     *
     * @return string
     */
    private function documentDisk(): string
    {
        return config('filesystems.default', 'local');
    }
}
