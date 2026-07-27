<?php

namespace App\Copilot\Support;

use App\Copilot\Communications\Enums\CommunicationStatus;
use App\Copilot\Documents\Enums\ReviewStatus;
use App\Copilot\Documents\Services\SubDocumentSendMessageService;
use App\Copilot\Identity\MvpUser;
use App\Models\Copilot\Communication;
use App\Models\Copilot\ExtractedData;
use App\Models\Copilot\OriginalDocument;
use App\Models\Copilot\SubDocument;

class MvpStateService
{
    public function __construct(
        private readonly SubDocumentSendMessageService $sendMessages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forActor(MvpUser $actor): array
    {
        return [
            'assistant' => $this->assistantState($actor),
            'copilot' => $this->copilotState($actor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assistantState(MvpUser $actor): array
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
            'history' => $history->map(fn ($communication) => $this->communication($communication))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function copilotState(MvpUser $actor): array
    {
        $documents = SubDocument::query()
            ->with(['originalDocument', 'extractedData'])
            ->whereHas('originalDocument', fn ($query) => $query->where('tenant_id', $actor->tenantId))
            ->latest()
            ->limit(40)
            ->get();

        $originalCount = OriginalDocument::query()->where('tenant_id', $actor->tenantId)->count();
        $confidenceThreshold = (int) config('services.bedrock.mvp_confidence_threshold', 80);

        return [
            'metrics' => [
                ['value' => $originalCount, 'label' => 'Documenti analizzati'],
                ['value' => SubDocument::query()->whereHas('originalDocument', fn ($query) => $query->where('tenant_id', $actor->tenantId))->count(), 'label' => 'Sotto-documenti rilevati'],
                ['value' => ExtractedData::query()->whereHas('subDocument.originalDocument', fn ($query) => $query->where('tenant_id', $actor->tenantId))->where('confidence_score', '>=', $confidenceThreshold)->count(), 'label' => 'Campi con confidenza'],
                ['value' => SubDocument::query()->whereHas('originalDocument', fn ($query) => $query->where('tenant_id', $actor->tenantId))->where('review_status', ReviewStatus::NeedsReview)->count(), 'label' => 'Da verificare'],
                ['value' => SubDocument::query()->whereHas('originalDocument', fn ($query) => $query->where('tenant_id', $actor->tenantId))->where('review_status', ReviewStatus::Quarantined)->count(), 'label' => 'In quarantena'],
            ],
            'documents' => $documents->map(fn ($document) => $this->document($document))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function communication(Communication $communication): array
    {
        return [
            'id' => $communication->id,
            'prompt' => $communication->prompt,
            'tone' => $communication->tone,
            'style' => $communication->style,
            'title' => $communication->generated_title,
            'body' => $communication->generated_body,
            'previewUrl' => route('api.v1.communications.preview', ['communication' => $communication->id], false),
            'exportUrl' => route('api.v1.communications.export', ['communication' => $communication->id], false),
            'coverImageUrl' => $this->coverImageUrl($communication),
            'coverStatus' => $communication->cover_status->value,
            'coverStatusLabel' => $communication->cover_status->label(),
            'coverError' => $communication->cover_error,
            'generationStatus' => $communication->generation_status->value,
            'generationStatusLabel' => $communication->generation_status->label(),
            'error' => $communication->error_message,
            'status' => $communication->status->label(),
            'createdAt' => $communication->created_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * URL relativo dell'endpoint di serving: vedi la nota in
     * DocumentController::store sul mixed-content dietro Traefik.
     *
     * Il percorso non cambia quando la copertina viene sostituita, quindi porta
     * una versione derivata dalla chiave dell'oggetto: senza, il browser
     * continuerebbe a mostrare l'immagine precedente finche' la cache non scade.
     */
    public function coverImageUrl(Communication $communication): ?string
    {
        if (! $communication->cover_image_path) {
            return null;
        }

        return route('api.v1.communications.cover-image.show', [
            'communication' => $communication->id,
            'v' => substr(hash('xxh128', $communication->cover_image_path), 0, 12),
        ], false);
    }

    /**
     * @return array<string, mixed>
     */
    public function document(SubDocument $subDocument): array
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
        ];

        if ($subDocument->error_message) {
            $previewLines[] = 'Errore estrazione: '.$subDocument->error_message;
        }

        $sendMessage = $this->sendMessages->compose($subDocument);

        return [
            'id' => 'sub-'.$subDocument->id,
            'title' => $data?->document_type ?: $original?->original_filename,
            'employeeFirstName' => $data?->employee_first_name,
            'employeeLastName' => $data?->employee_last_name,
            'employee' => $employee !== '' ? $employee : null,
            'companyName' => $data?->company_name,
            'company' => $data?->company_name,
            'file' => $original?->original_filename,
            'documentDate' => $data?->document_date?->format('Y-m-d'),
            'date' => $data?->document_date?->format('d/m/Y'),
            'pages' => $pages,
            'documentType' => $data?->document_type,
            'type' => $data?->document_type,
            'description' => $data?->description,
            'confidence' => $confidence,
            'reviewStatus' => $subDocument->review_status->value,
            'reviewStatusLabel' => $subDocument->review_status->label(),
            'error' => $subDocument->error_message,
            // URL relativo: vedi nota in DocumentController::store. Un URL assoluto
            // sarebbe generato con schema "http://" dietro Traefik e bloccato dal
            // browser (mixed-content sull'iframe e sul fetch dell'anteprima).
            'previewUrl' => route('api.v1.documents.preview', ['subDocument' => $subDocument->id], false),
            'sendRecipient' => $sendMessage['recipient'],
            'sendSubject' => $sendMessage['subject'],
            'sendBody' => $sendMessage['body'],
            'sendPreviewUrl' => route('api.v1.documents.send-preview', ['subDocument' => $subDocument->id], false),
            'sendExportUrl' => route('api.v1.documents.send-export', ['subDocument' => $subDocument->id], false),
            'previewLines' => $previewLines,
        ];
    }
}
