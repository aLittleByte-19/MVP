<?php

namespace App\Mvp\Documents\Adapters\Outbound\Persistence;

use App\Models\ExtractedData;
use App\Models\OriginalDocument;
use App\Models\SubDocument;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentRecord;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageContext;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentPage;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentRecord;

/**
 * Adapter secondario: implementa {@see DocumentRepository} sopra Eloquent.
 * Unico punto della codebase dove l'aggregato documentale viene interrogato
 * o mutato direttamente via Eloquent per il dominio Documents.
 */
class EloquentDocumentRepository implements DocumentRepository
{
    public function createOriginalDocument(array $attributes): int
    {
        return OriginalDocument::create($attributes)->id;
    }

    public function findOriginalDocument(int $id): OriginalDocumentRecord
    {
        return $this->toOriginalDocumentRecord(OriginalDocument::query()->findOrFail($id));
    }

    public function updateOriginalDocument(int $id, array $attributes): void
    {
        OriginalDocument::query()->whereKey($id)->firstOrFail()->update($attributes);
    }

    public function deleteOriginalDocumentWithWorkflowTasks(int $id): void
    {
        $original = OriginalDocument::query()->findOrFail($id);
        // I task workflow sono legati da una relazione morph, senza foreign
        // key: vanno rimossi insieme al documento che li ha generati.
        $original->workflowTasks()->delete();
        $original->delete();
    }

    public function paginateSubDocuments(string $tenantId, array $filters, int $page, int $perPage): SubDocumentPage
    {
        $query = SubDocument::query()
            ->whereHas('originalDocument', fn ($documents) => $documents->where('tenant_id', $tenantId));

        // UC-35: ricerca su nome, cognome e azienda dei dati estratti.
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->whereHas('extractedData', function ($data) use ($search): void {
                $like = '%'.$search.'%';
                $data->where('employee_first_name', 'like', $like)
                    ->orWhere('employee_last_name', 'like', $like)
                    ->orWhere('company_name', 'like', $like);
            });
        }

        // UC-36: lo stato di invio coincide con l'avvenuto scaricamento del PDF.
        if ($sendStatus = $filters['sendStatus'] ?? null) {
            $query->where('send_status', $sendStatus);
        }

        // UC-37: soglia di confidenza, sopra o sotto il valore indicato.
        $threshold = $filters['confidenceThreshold'] ?? null;
        if ($threshold !== null) {
            $operator = ($filters['confidenceCriterion'] ?? 'below') === 'above' ? '>=' : '<';
            $query->whereHas(
                'extractedData',
                fn ($data) => $data->whereNotNull('confidence_score')->where('confidence_score', $operator, (int) $threshold),
            );
        }

        // UC-38: mese e anno del documento, indipendenti fra loro.
        if (($month = $filters['month'] ?? null) !== null) {
            $query->whereHas('extractedData', fn ($data) => $data->whereMonth('document_date', (int) $month));
        }

        if (($year = $filters['year'] ?? null) !== null) {
            $query->whereHas('extractedData', fn ($data) => $data->whereYear('document_date', (int) $year));
        }

        $paginator = $query->latest()->paginate(perPage: $perPage, page: $page, columns: ['id']);

        return new SubDocumentPage(
            subDocumentIds: $paginator->pluck('id')->map(fn ($id) => (int) $id)->all(),
            total: $paginator->total(),
            page: $paginator->currentPage(),
            perPage: $paginator->perPage(),
        );
    }

    public function findSubDocument(int $id): SubDocumentRecord
    {
        return $this->toSubDocumentRecord(SubDocument::query()->findOrFail($id));
    }

    public function findSendMessageContext(int $subDocumentId): SendMessageContext
    {
        $subDocument = SubDocument::query()->with(['originalDocument', 'extractedData'])->findOrFail($subDocumentId);
        $data = $subDocument->extractedData;

        return new SendMessageContext(
            employeeFirstName: $data?->employee_first_name,
            employeeLastName: $data?->employee_last_name,
            documentType: $data?->document_type,
            companyName: $data?->company_name,
            documentDateDisplay: $data?->document_date?->format('d/m/Y'),
            description: $data?->description,
            sendRecipientOverride: $subDocument->send_recipient_override,
            sendSubjectOverride: $subDocument->send_subject_override,
            sendBodyOverride: $subDocument->send_body_override,
            originalFilename: $subDocument->originalDocument?->original_filename ?: 'documento.pdf',
            sendStatus: $subDocument->send_status->value,
        );
    }

    public function subDocumentHasExtractedData(int $subDocumentId): bool
    {
        return ExtractedData::query()->where('sub_document_id', $subDocumentId)->exists();
    }

    public function createSubDocument(array $attributes): int
    {
        return SubDocument::create($attributes)->id;
    }

    public function updateSubDocument(int $id, array $attributes): void
    {
        SubDocument::query()->whereKey($id)->firstOrFail()->update($attributes);
    }

    public function deleteSubDocument(int $id): void
    {
        SubDocument::query()->whereKey($id)->firstOrFail()->delete();
    }

    public function originalDocumentIdForSubDocument(int $subDocumentId): int
    {
        return (int) SubDocument::query()->findOrFail($subDocumentId)->original_document_id;
    }

    public function originalDocumentHasRemainingSubDocuments(int $originalDocumentId): bool
    {
        return SubDocument::query()->where('original_document_id', $originalDocumentId)->exists();
    }

    public function deleteExistingSubDocuments(int $originalDocumentId): array
    {
        $splits = SubDocument::query()->where('original_document_id', $originalDocumentId)->get(['id', 'file_path']);
        $paths = $splits->pluck('file_path')->filter()->values()->all();

        $splits->each(fn (SubDocument $split) => $split->delete());

        return $paths;
    }

    public function saveExtractedData(int $subDocumentId, array $attributes): void
    {
        ExtractedData::updateOrCreate(['sub_document_id' => $subDocumentId], $attributes);
    }

    public function deleteExtractedData(int $subDocumentId): void
    {
        ExtractedData::query()->where('sub_document_id', $subDocumentId)->delete();
    }

    private function toOriginalDocumentRecord(OriginalDocument $document): OriginalDocumentRecord
    {
        return new OriginalDocumentRecord(
            id: $document->id,
            tenantId: $document->tenant_id,
            filePath: $document->file_path,
            manualDocumentType: $document->manual_document_type,
            manualCompanyName: $document->manual_company_name,
            manualReferenceMonth: $document->manual_reference_month,
            manualReferenceYear: $document->manual_reference_year,
            processingStatus: $document->processing_status->value,
            ocrText: $document->ocr_text,
            ocrPages: $document->ocr_pages ?? [],
            ocrConfidenceAvg: $document->ocr_confidence_avg,
            s3Bucket: $document->s3_bucket,
            s3Key: $document->s3_key,
            workflowCompleted: $document->workflow_completed_at !== null,
            workflowExecutionArn: $document->workflow_execution_arn,
        );
    }

    private function toSubDocumentRecord(SubDocument $subDocument): SubDocumentRecord
    {
        return new SubDocumentRecord(
            id: $subDocument->id,
            originalDocumentId: $subDocument->original_document_id,
            filePath: $subDocument->file_path,
            startPage: $subDocument->start_page,
            endPage: $subDocument->end_page,
        );
    }
}
