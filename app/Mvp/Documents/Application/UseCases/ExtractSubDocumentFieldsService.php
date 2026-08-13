<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Exceptions\InvalidAiOutputException;
use App\Mvp\Documents\Application\Support\OcrRangeReader;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Events\AiOutputRejected;
use App\Mvp\Documents\Domain\Events\SubDocumentFieldsExtracted;
use App\Mvp\Documents\Domain\Ports\Inbound\ExtractSubDocumentFieldsUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentAiGatewayPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\ValueObjects\ExtractedDataChanges;
use App\Mvp\Documents\Domain\ValueObjects\OriginalDocumentRecord;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentChanges;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentRecord;
use App\Mvp\Support\Persistence\TransactionManagerPort;
use Psr\Log\LoggerInterface;

/**
 * Applicazione: estrae i campi di un sotto-documento (destinatario) gia'
 * creato da uno split, un destinatario alla volta. Separata da
 * ProcessDocumentService (che orchestra split + Fpdi e chiama questa porta
 * per ciascun destinatario appena creato) — stessa logica di
 * DocumentProcessingService::extractAndSaveFields() prima del refactor
 * esagonale, ora isolata dalla manipolazione PDF che la rendeva
 * testabile solo insieme a quest'ultima (vedi ADR 0010).
 */
class ExtractSubDocumentFieldsService implements ExtractSubDocumentFieldsUseCase
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentAiGatewayPort $ai,
        private readonly DocumentEventDispatcherPort $events,
        private readonly LoggerInterface $logger,
        private readonly OcrRangeReader $ocrRange,
        private readonly TransactionManagerPort $tx,
        private readonly int $confidenceThreshold = 80,
    ) {}

    public function extractAndSaveFields(int $subDocumentId): void
    {
        $originalId = $this->documents->originalDocumentIdForSubDocument($subDocumentId);

        $this->extractAndSaveFieldsWithContext($subDocumentId, $this->documents->findOriginalDocument($originalId));
    }

    public function extractAndSaveFieldsWithContext(int $subDocumentId, OriginalDocumentRecord $original): void
    {
        $subDocument = $this->documents->findSubDocument($subDocumentId);

        try {
            $aiFields = $this->extractFields($subDocument, $original);
            // I metadati impostati in upload prevalgono sull'estrazione AI.
            $fields = $this->applyManualMetadataOverrides($aiFields, $original);
            $confidenceScore = $this->computeConfidenceScore($aiFields, $subDocument, $original);
            $reviewStatus = $this->reviewStatusForConfidence($confidenceScore);

            $this->tx->run(function () use ($subDocumentId, $reviewStatus, $fields, $confidenceScore, $aiFields): void {
                $this->documents->updateSubDocument($subDocumentId, SubDocumentChanges::none()
                    ->withReviewStatus($reviewStatus)
                    ->withErrorMessage(null));
                $this->documents->saveExtractedData($subDocumentId, ExtractedDataChanges::none()
                    ->withEmployeeFirstName($fields['employee_first_name'])
                    ->withEmployeeLastName($fields['employee_last_name'])
                    ->withCompanyName($fields['company_name'])
                    ->withDocumentDate($fields['document_date'])
                    ->withDocumentType($fields['document_type'])
                    ->withDescription($fields['description'])
                    ->withConfidenceScore($confidenceScore)
                    ->withAiPayload($aiFields));
            });

            $this->events->dispatch(new SubDocumentFieldsExtracted(
                $subDocumentId,
                $original->tenantId,
                $reviewStatus->value,
                $confidenceScore,
                $this->confidenceThreshold,
            ));
        } catch (InvalidAiOutputException $e) {
            $safeMessage = 'Output AI non conforme: sotto-documento in quarantena.';
            $this->logger->warning('ExtractSubDocumentFieldsService: AI output quarantined', [
                'sub_document_id' => $subDocumentId,
                'operation' => $e->operation(),
                'errors' => $e->errors(),
            ]);

            $this->tx->run(function () use ($subDocumentId, $safeMessage): void {
                $this->documents->deleteExtractedData($subDocumentId);
                $this->documents->updateSubDocument($subDocumentId, SubDocumentChanges::none()
                    ->withReviewStatus(ReviewStatus::Quarantined)
                    ->withErrorMessage($safeMessage));
            });
            $this->events->dispatch(new AiOutputRejected($subDocumentId, $original->tenantId, $e->operation(), $e->errors()));
        } catch (\Throwable $e) {
            $this->logger->error('ExtractSubDocumentFieldsService: extraction failed', ['sub_document_id' => $subDocumentId, 'message' => $e->getMessage()]);
            $this->documents->updateSubDocument($subDocumentId, SubDocumentChanges::none()
                ->withErrorMessage($this->ai->formatUserError($e, 'Estrazione campi non disponibile. Verifica configurazione e permessi Bedrock.')));

            throw $e;
        }
    }

    private function computeConfidenceScore(array $aiFields, SubDocumentRecord $subDocument, OriginalDocumentRecord $original): int
    {
        $keyFields = array_values(array_diff(
            ['employee_first_name', 'employee_last_name', 'company_name', 'document_date'],
            $this->manuallyDeclaredKeyFields($original),
        ));

        $ocrConfidence = $this->ocrConfidenceForRange($original, $subDocument->startPage, $subDocument->endPage);

        if ($keyFields === []) {
            return max(0, min(100, (int) round($ocrConfidence)));
        }

        $found = 0;
        foreach ($keyFields as $key) {
            if (isset($aiFields[$key]) && trim((string) $aiFields[$key]) !== '') {
                $found++;
            }
        }

        $completeness = $found / count($keyFields);

        return max(0, min(100, (int) round($ocrConfidence * $completeness)));
    }

    /**
     * @return list<string>
     */
    private function manuallyDeclaredKeyFields(OriginalDocumentRecord $original): array
    {
        $declared = [];

        if ($original->manualCompanyName !== null) {
            $declared[] = 'company_name';
        }

        if ($original->manualReferenceMonth !== null || $original->manualReferenceYear !== null) {
            $declared[] = 'document_date';
        }

        return $declared;
    }

    private function ocrConfidenceForRange(OriginalDocumentRecord $original, int $startPage, int $endPage): float
    {
        $pages = $original->ocrPages;

        if ($pages !== []) {
            $values = [];

            foreach ($pages as $page) {
                $number = (int) ($page['page'] ?? 0);

                if ($number < $startPage || $number > $endPage) {
                    continue;
                }

                if (isset($page['confidenceAvg']) && $page['confidenceAvg'] !== null) {
                    $values[] = (float) $page['confidenceAvg'];
                }
            }

            if ($values !== []) {
                return array_sum($values) / count($values);
            }
        }

        return (float) ($original->ocrConfidenceAvg ?? 0.0);
    }

    private function reviewStatusForConfidence(?int $confidenceScore): ReviewStatus
    {
        if ($confidenceScore !== null && $confidenceScore >= $this->confidenceThreshold) {
            return ReviewStatus::AutoValidated;
        }

        return ReviewStatus::NeedsReview;
    }

    /**
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}
     */
    private function extractFields(SubDocumentRecord $subDocument, OriginalDocumentRecord $original): array
    {
        $ocrText = $this->ocrRange->textForRange($original, $subDocument->startPage, $subDocument->endPage, $this->ocrRange->nonce());

        if ($ocrText === '') {
            throw new \RuntimeException('Testo OCR non disponibile per l\'intervallo di pagine del destinatario.');
        }

        return $this->ai->extractFields($ocrText);
    }

    /**
     * @param  array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}  $fields
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}
     */
    private function applyManualMetadataOverrides(array $fields, OriginalDocumentRecord $original): array
    {
        if (! $original->hasManualUploadMetadata()) {
            return $fields;
        }

        if ($original->manualDocumentType !== null) {
            $fields['document_type'] = $original->manualDocumentType;
        }

        if ($original->manualCompanyName !== null) {
            $fields['company_name'] = $original->manualCompanyName;
        }

        $month = $original->manualReferenceMonth;
        $year = $original->manualReferenceYear;

        if ($month !== null && $year !== null) {
            $fields['document_date'] = sprintf('%04d-%02d-01', $year, $month);
        } elseif ($year !== null || $month !== null) {
            $fields['document_date'] = $this->mergeManualDateWithAi($fields['document_date'] ?? null, $month, $year);
        }

        return $fields;
    }

    private function mergeManualDateWithAi(?string $aiDate, ?int $month, ?int $year): ?string
    {
        $aiYear = null;
        $aiMonth = null;

        if (is_string($aiDate) && preg_match('/^(\d{4})-(\d{2})/', $aiDate, $matches) === 1) {
            $aiYear = (int) $matches[1];
            $aiMonth = (int) $matches[2];
        }

        $resolvedYear = $year ?? $aiYear;
        $resolvedMonth = $month ?? $aiMonth;

        if ($resolvedYear === null || $resolvedMonth === null) {
            return $aiDate;
        }

        return sprintf('%04d-%02d-01', $resolvedYear, $resolvedMonth);
    }
}
