<?php

namespace Tests\DomainUnit\Documents\Fakes;

use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentRecord;
use App\Mvp\Documents\Domain\ValueObjects\SendMessageContext;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentPage;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentRecord;

/**
 * Adapter secondario di test: implementa DocumentRepository in memoria,
 * senza Eloquent ne' database (vedi ADR 0010, refactory.md Compito 3 punto 2).
 */
final class InMemoryDocumentRepository implements DocumentRepository
{
    /** @var array<int, array<string, mixed>> */
    private array $originals = [];

    /** @var array<int, array<string, mixed>> */
    private array $subDocuments = [];

    /** @var array<int, array<string, mixed>> */
    private array $extractedData = [];

    private int $nextOriginalId = 1;

    private int $nextSubDocumentId = 1;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function seedOriginal(int $id, array $attributes = []): void
    {
        $this->originals[$id] = array_merge($this->originalDefaults($id), $this->normalize($attributes));
        $this->nextOriginalId = max($this->nextOriginalId, $id + 1);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function seedSubDocument(int $id, int $originalDocumentId, array $attributes = []): void
    {
        $this->subDocuments[$id] = array_merge($this->subDocumentDefaults($id, $originalDocumentId), $this->normalize($attributes));
        $this->nextSubDocumentId = max($this->nextSubDocumentId, $id + 1);
    }

    public function createOriginalDocument(array $attributes): int
    {
        $id = $this->nextOriginalId++;
        $this->originals[$id] = array_merge($this->originalDefaults($id), $this->normalize($attributes));

        return $id;
    }

    public function findOriginalDocument(int $id): OriginalDocumentRecord
    {
        if (! isset($this->originals[$id])) {
            throw new \RuntimeException("OriginalDocument {$id} non seminato nel fake repository.");
        }

        return $this->toOriginalRecord($this->originals[$id]);
    }

    public function updateOriginalDocument(int $id, array $attributes): void
    {
        if (! isset($this->originals[$id])) {
            throw new \RuntimeException("OriginalDocument {$id} non seminato nel fake repository.");
        }

        $this->originals[$id] = array_merge($this->originals[$id], $this->normalize($attributes));
    }

    public function deleteOriginalDocumentWithWorkflowTasks(int $id): void
    {
        unset($this->originals[$id]);
    }

    public function paginateSubDocuments(string $tenantId, array $filters, int $page, int $perPage): SubDocumentPage
    {
        return new SubDocumentPage([], 0, $page, $perPage);
    }

    public function findSubDocument(int $id): SubDocumentRecord
    {
        if (! isset($this->subDocuments[$id])) {
            throw new \RuntimeException("SubDocument {$id} non seminato nel fake repository.");
        }

        $row = $this->subDocuments[$id];

        return new SubDocumentRecord(
            id: $row['id'],
            originalDocumentId: $row['original_document_id'],
            filePath: $row['file_path'],
            startPage: $row['start_page'],
            endPage: $row['end_page'],
            originalFilename: $row['original_filename'],
        );
    }

    public function findSendMessageContext(int $subDocumentId): SendMessageContext
    {
        $row = $this->subDocuments[$subDocumentId] ?? throw new \RuntimeException("SubDocument {$subDocumentId} non seminato nel fake repository.");

        return new SendMessageContext(
            employeeFirstName: $row['employee_first_name'] ?? null,
            employeeLastName: $row['employee_last_name'] ?? null,
            documentType: $row['document_type'] ?? null,
            companyName: $row['company_name'] ?? null,
            documentDateDisplay: $row['document_date_display'] ?? null,
            description: $row['description'] ?? null,
            sendRecipientOverride: $row['send_recipient_override'] ?? null,
            sendSubjectOverride: $row['send_subject_override'] ?? null,
            sendBodyOverride: $row['send_body_override'] ?? null,
            originalFilename: $row['original_filename'] ?? 'documento.pdf',
            sendStatus: $row['send_status'] ?? 'pending',
        );
    }

    public function subDocumentHasExtractedData(int $subDocumentId): bool
    {
        return isset($this->extractedData[$subDocumentId]);
    }

    public function createSubDocument(array $attributes): int
    {
        $id = $this->nextSubDocumentId++;
        $originalDocumentId = (int) ($attributes['original_document_id'] ?? 0);
        $this->subDocuments[$id] = array_merge($this->subDocumentDefaults($id, $originalDocumentId), $this->normalize($attributes));

        return $id;
    }

    public function updateSubDocument(int $id, array $attributes): void
    {
        if (! isset($this->subDocuments[$id])) {
            throw new \RuntimeException("SubDocument {$id} non seminato nel fake repository.");
        }

        $this->subDocuments[$id] = array_merge($this->subDocuments[$id], $this->normalize($attributes));
    }

    public function deleteSubDocument(int $id): void
    {
        unset($this->subDocuments[$id]);
    }

    public function originalDocumentIdForSubDocument(int $subDocumentId): int
    {
        return $this->subDocuments[$subDocumentId]['original_document_id']
            ?? throw new \RuntimeException("SubDocument {$subDocumentId} non seminato nel fake repository.");
    }

    public function originalDocumentHasRemainingSubDocuments(int $originalDocumentId): bool
    {
        foreach ($this->subDocuments as $row) {
            if ($row['original_document_id'] === $originalDocumentId) {
                return true;
            }
        }

        return false;
    }

    public function deleteExistingSubDocuments(int $originalDocumentId): array
    {
        $paths = [];

        foreach ($this->subDocuments as $id => $row) {
            if ($row['original_document_id'] === $originalDocumentId) {
                $paths[] = $row['file_path'];
                unset($this->subDocuments[$id]);
            }
        }

        return $paths;
    }

    public function saveExtractedData(int $subDocumentId, array $attributes): void
    {
        $this->extractedData[$subDocumentId] = $this->normalize($attributes);
    }

    public function deleteExtractedData(int $subDocumentId): void
    {
        unset($this->extractedData[$subDocumentId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function extractedDataFor(int $subDocumentId): ?array
    {
        return $this->extractedData[$subDocumentId] ?? null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalize(array $attributes): array
    {
        return array_map(
            static fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value,
            $attributes,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function originalDefaults(int $id): array
    {
        return [
            'id' => $id,
            'tenant_id' => 'tenant-test',
            'file_path' => 'documents/originals/test.pdf',
            'manual_document_type' => null,
            'manual_company_name' => null,
            'manual_reference_month' => null,
            'manual_reference_year' => null,
            'processing_status' => 'pending',
            'ocr_text' => null,
            'ocr_pages' => [],
            'ocr_confidence_avg' => null,
            's3_bucket' => null,
            's3_key' => null,
            'workflow_completed_at' => null,
            'workflow_execution_arn' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subDocumentDefaults(int $id, int $originalDocumentId): array
    {
        return [
            'id' => $id,
            'original_document_id' => $originalDocumentId,
            'file_path' => "documents/sub/{$id}.pdf",
            'start_page' => 1,
            'end_page' => 1,
            'original_filename' => 'documento.pdf',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function toOriginalRecord(array $row): OriginalDocumentRecord
    {
        return new OriginalDocumentRecord(
            id: $row['id'],
            tenantId: $row['tenant_id'],
            filePath: $row['file_path'],
            manualDocumentType: $row['manual_document_type'],
            manualCompanyName: $row['manual_company_name'],
            manualReferenceMonth: $row['manual_reference_month'],
            manualReferenceYear: $row['manual_reference_year'],
            processingStatus: $row['processing_status'],
            ocrText: $row['ocr_text'],
            ocrPages: $row['ocr_pages'],
            ocrConfidenceAvg: $row['ocr_confidence_avg'],
            s3Bucket: $row['s3_bucket'],
            s3Key: $row['s3_key'],
            workflowCompleted: $row['workflow_completed_at'] !== null,
            workflowExecutionArn: $row['workflow_execution_arn'],
        );
    }
}
