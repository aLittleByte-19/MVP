<?php

namespace App\Copilot\Support;

use App\Copilot\Communications\Enums\CommunicationStatus;
use App\Copilot\Documents\Enums\ReviewStatus;
use App\Copilot\Identity\PocUser;
use App\Models\Copilot\Communication;
use App\Models\Copilot\ExtractedData;
use App\Models\Copilot\OriginalDocument;
use App\Models\Copilot\SubDocument;

class PocStateService
{
    /**
     * @return array<string, mixed>
     */
    public function forActor(PocUser $actor): array
    {
        return [
            'assistant' => $this->assistantState($actor),
            'copilot' => $this->copilotState($actor),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assistantState(PocUser $actor): array
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
    public function copilotState(PocUser $actor): array
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
            'status' => $communication->status->label(),
            'createdAt' => $communication->created_at?->format('d/m/Y H:i'),
        ];
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
            'File originale: '.($original?->original_filename ?: 'Non disponibile').'.',
            'Campi rilevati dal servizio AI configurato.',
        ];

        if ($subDocument->error_message) {
            $previewLines[] = 'Errore estrazione: '.$subDocument->error_message;
        }

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
            'previewUrl' => route('api.v1.documents.preview', ['subDocument' => $subDocument->id]),
            'previewLines' => $previewLines,
        ];
    }
}
