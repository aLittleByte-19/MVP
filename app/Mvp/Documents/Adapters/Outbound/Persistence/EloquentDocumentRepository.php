<?php

namespace App\Mvp\Documents\Adapters\Outbound\Persistence;

use App\Models\ExtractedData;
use App\Models\OriginalDocument;
use App\Models\SubDocument;
use App\Mvp\Documents\Domain\Entities\SubDocument as SubDocumentEntity;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\ValueObjects\ExtractedDataChanges;
use App\Mvp\Documents\Domain\ValueObjects\NewOriginalDocument;
use App\Mvp\Documents\Domain\ValueObjects\NewSubDocument;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentChanges;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentRecord;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageContext;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentChanges;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentPage;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adapter secondario: implementa {@see DocumentRepository} sopra Eloquent.
 * Unico punto della codebase dove l'aggregato documentale viene interrogato
 * o mutato direttamente via Eloquent per il dominio Documents.
 */
class EloquentDocumentRepository implements DocumentRepository
{
    public function createOriginalDocument(NewOriginalDocument $document): int
    {
        return OriginalDocument::create($document->toArray())->id;
    }

    public function findOriginalDocument(int $id): OriginalDocumentRecord
    {
        return $this->toOriginalDocumentRecord(OriginalDocument::query()->findOrFail($id));
    }

    public function updateOriginalDocument(int $id, OriginalDocumentChanges $changes): void
    {
        OriginalDocument::query()->whereKey($id)->firstOrFail()->update($this->snakeCaseKeys($changes->toArray()));
    }

    public function deleteOriginalDocumentWithWorkflowTasks(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $original = OriginalDocument::query()->findOrFail($id);
            // I task workflow sono legati da una relazione morph, senza foreign
            // key: vanno rimossi insieme al documento che li ha generati.
            $original->workflowTasks()->delete();
            $original->delete();
        });
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

    public function findSubDocument(int $id): SubDocumentEntity
    {
        $subDocument = SubDocument::query()->with('originalDocument')->findOrFail($id);

        return SubDocumentEntity::fromRecord($this->toSubDocumentRecord($subDocument), $subDocument->review_status);
    }

    public function saveSubDocument(SubDocumentEntity $subDocument): void
    {
        $changes = $subDocument->pendingChanges();

        if ($changes->isEmpty()) {
            return;
        }

        SubDocument::query()->whereKey($subDocument->id)->firstOrFail()->update($this->snakeCaseKeys($changes->toArray()));
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

    public function createSubDocument(NewSubDocument $subDocument): int
    {
        return SubDocument::create($subDocument->toArray())->id;
    }

    public function updateSubDocument(int $id, SubDocumentChanges $changes): void
    {
        SubDocument::query()->whereKey($id)->firstOrFail()->update($this->snakeCaseKeys($changes->toArray()));
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

    public function subDocumentIdsWithExtractedData(int $originalDocumentId): array
    {
        return SubDocument::query()
            ->where('original_document_id', $originalDocumentId)
            ->whereHas('extractedData')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function deleteExistingSubDocuments(int $originalDocumentId): array
    {
        return DB::transaction(function () use ($originalDocumentId): array {
            $splits = SubDocument::query()->where('original_document_id', $originalDocumentId)->get(['id', 'file_path']);
            $paths = $splits->pluck('file_path')->filter()->values()->all();

            $splits->each(fn (SubDocument $split) => $split->delete());

            return $paths;
        });
    }

    public function saveExtractedData(int $subDocumentId, ExtractedDataChanges $changes): void
    {
        ExtractedData::updateOrCreate(['sub_document_id' => $subDocumentId], $this->snakeCaseKeys($changes->toArray()));
    }

    public function countSubDocuments(int $originalDocumentId): int
    {
        return SubDocument::query()->where('original_document_id', $originalDocumentId)->count();
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
            errorMessage: $document->error_message,
        );
    }

    /**
     * I *Changes VO usano chiavi camelCase (i nomi dei loro metodi with*()),
     * non i nomi delle colonne: la traduzione verso lo schema DB e'
     * responsabilita' di questo adapter, non del dominio.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function snakeCaseKeys(array $attributes): array
    {
        $result = [];

        foreach ($attributes as $key => $value) {
            $result[Str::snake($key)] = $value;
        }

        return $result;
    }

    private function toSubDocumentRecord(SubDocument $subDocument): SubDocumentRecord
    {
        return new SubDocumentRecord(
            id: $subDocument->id,
            originalDocumentId: $subDocument->original_document_id,
            filePath: $subDocument->file_path,
            startPage: $subDocument->start_page,
            endPage: $subDocument->end_page,
            originalFilename: $subDocument->originalDocument?->original_filename ?: 'documento.pdf',
        );
    }
}
