<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Exceptions\InvalidAiOutputException;
use App\Mvp\Documents\Application\Support\OcrRangeReader;
use App\Mvp\Documents\Domain\Entities\OriginalDocument;
use App\Mvp\Documents\Domain\Entities\SubDocument;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Events\AiOutputRejected;
use App\Mvp\Documents\Domain\Events\SubDocumentFieldsExtracted;
use App\Mvp\Documents\Domain\Ports\Inbound\ExtractSubDocumentFieldsUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentAiGatewayPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentEventDispatcherPort;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\Support\CodiceFiscale;
use App\Mvp\Documents\Domain\Support\FieldConfidence;
use App\Mvp\Documents\Domain\ValueObjects\ExtractedDataChanges;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentChanges;
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
    /**
     * I campi che identificano il documento e il suo destinatario: sono questi
     * a determinare se l'estrazione regge, non i nove estratti in tutto.
     *
     * @var list<string>
     */
    private const KEY_FIELDS = ['employee_first_name', 'employee_last_name', 'company_name', 'document_date'];

    /**
     * Campi che il modello copia dal documento invece di dedurli, e che quindi
     * si possono ritrovare fra le righe OCR. Restano fuori `document_type` e
     * `description`, che il modello compone, e `document_date`, che normalizza
     * e va cercata a parte nelle rese in cui il foglio la scrive.
     *
     * @var list<string>
     */
    private const TRANSCRIBED_FIELDS = [
        'employee_first_name',
        'employee_last_name',
        'company_name',
        'recipient_email',
        'fiscal_code',
        'employee_id',
    ];

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentAiGatewayPort $ai,
        private readonly DocumentEventDispatcherPort $events,
        private readonly LoggerInterface $logger,
        private readonly OcrRangeReader $ocrRange,
        private readonly TransactionManagerPort $tx,
        private readonly int $confidenceThreshold = 80,
        // Il codice fiscale identifica la persona: un carattere letto male
        // assegna il documento a un'altra, quindi ha una soglia piu' alta di
        // quella generale. Non e' pero' il «mapping CF >= 99%» del Capitolato:
        // quello e' un obiettivo di accuratezza misurato sulla popolazione, e
        // portato a soglia per documento renderebbe irraggiungibile la
        // validazione automatica — Textract legge un codice nitido a 97,7.
        private readonly int $fiscalCodeConfidenceThreshold = 95,
    ) {}

    public function extractAndSaveFields(int $subDocumentId): void
    {
        $originalId = $this->documents->originalDocumentIdForSubDocument($subDocumentId);

        $this->extractAndSaveFieldsWithContext($subDocumentId, $this->documents->findOriginalDocument($originalId));
    }

    public function extractAndSaveFieldsWithContext(int $subDocumentId, OriginalDocument $original): void
    {
        $subDocument = $this->documents->findSubDocument($subDocumentId);

        try {
            $aiFields = $this->extractFields($subDocument, $original);
            // I metadati impostati in upload prevalgono sull'estrazione AI.
            $fields = $this->applyManualMetadataOverrides($aiFields, $original);
            $fieldConfidences = $this->fieldConfidences($aiFields, $subDocument, $original);
            $confidenceScore = $this->computeConfidenceScore($aiFields, $fieldConfidences, $subDocument, $original);
            $reviewStatus = $this->reviewStatusForConfidence($confidenceScore, $fieldConfidences);

            $this->tx->run(function () use ($subDocumentId, $subDocument, $reviewStatus, $fields, $confidenceScore, $fieldConfidences, $aiFields): void {
                if ($reviewStatus === ReviewStatus::AutoValidated) {
                    $subDocument->markAutoValidated();
                } else {
                    $subDocument->markNeedsReview();
                }
                $this->documents->saveSubDocument($subDocument);
                $this->documents->saveExtractedData($subDocumentId, ExtractedDataChanges::none()
                    ->withEmployeeFirstName($fields['employee_first_name'])
                    ->withEmployeeLastName($fields['employee_last_name'])
                    ->withCompanyName($fields['company_name'])
                    ->withDocumentDate($fields['document_date'])
                    ->withDocumentType($fields['document_type'])
                    ->withDescription($fields['description'])
                    ->withRecipientEmail($fields['recipient_email'])
                    ->withFiscalCode($fields['fiscal_code'])
                    ->withEmployeeId($fields['employee_id'])
                    ->withConfidenceScore($confidenceScore)
                    ->withFieldConfidences($fieldConfidences)
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

            $this->tx->run(function () use ($subDocumentId, $subDocument, $safeMessage): void {
                $this->documents->deleteExtractedData($subDocumentId);
                $subDocument->markQuarantined($safeMessage);
                $this->documents->saveSubDocument($subDocument);
            });
            $this->events->dispatch(new AiOutputRejected($subDocumentId, $original->tenantId, $e->operation(), $e->errors()));
        } catch (\Throwable $e) {
            $this->logger->error('ExtractSubDocumentFieldsService: extraction failed', ['sub_document_id' => $subDocumentId, 'message' => $e->getMessage()]);
            $this->documents->updateSubDocument($subDocumentId, SubDocumentChanges::none()
                ->withErrorMessage($this->ai->formatUserError($e, 'Estrazione campi non disponibile. Verifica configurazione e permessi Bedrock.')));

            throw $e;
        }
    }

    /**
     * Confidenza di ogni campo trascritto, letta dalla riga OCR da cui proviene.
     *
     * I campi derivati non compaiono: tipologia e descrizione il modello li
     * compone, non li copia dal foglio, quindi cercarli fra le righe non
     * direbbe nulla sulla loro affidabilita'.
     *
     * @param  array<string, mixed>  $aiFields
     * @return array<string, float|null>
     */
    private function fieldConfidences(array $aiFields, SubDocument $subDocument, OriginalDocument $original): array
    {
        $blocks = $this->ocrBlocksForRange($original, $subDocument->startPage, $subDocument->endPage);

        $confidences = [];

        foreach (self::TRANSCRIBED_FIELDS as $field) {
            $confidences[$field] = FieldConfidence::forValue(
                isset($aiFields[$field]) ? (string) $aiFields[$field] : null,
                $blocks,
            );
        }

        $confidences['document_date'] = FieldConfidence::forDate(
            isset($aiFields['document_date']) ? (string) $aiFields['document_date'] : null,
            $blocks,
        );

        return $confidences;
    }

    /**
     * Punteggio del sotto-documento: la confidenza del suo campo chiave piu'
     * debole.
     *
     * Il minimo e non la media, perche' un documento e' affidabile quanto il
     * dato meno affidabile fra quelli che lo identificano: se il cognome del
     * destinatario e' illeggibile non conta che l'azienda sia nitida, il
     * documento rischia comunque di finire alla persona sbagliata.
     *
     * Un campo chiave assente vale zero. Un campo presente ma non rintracciabile
     * fra le righe ricade sulla media di pagina: e' il caso della data quando il
     * foglio la scrive in una forma che non riconosciamo, e in mancanza di
     * meglio la leggibilita' complessiva resta la stima piu' onesta.
     *
     * @param  array<string, mixed>  $aiFields
     * @param  array<string, float|null>  $fieldConfidences
     */
    private function computeConfidenceScore(array $aiFields, array $fieldConfidences, SubDocument $subDocument, OriginalDocument $original): int
    {
        $keyFields = array_values(array_diff(
            self::KEY_FIELDS,
            $this->manuallyDeclaredKeyFields($original),
        ));

        $pageConfidence = $this->ocrConfidenceForRange($original, $subDocument->startPage, $subDocument->endPage);

        if ($keyFields === []) {
            return max(0, min(100, (int) round($pageConfidence)));
        }

        $weakest = null;

        foreach ($keyFields as $key) {
            if (! isset($aiFields[$key]) || trim((string) $aiFields[$key]) === '') {
                return 0;
            }

            $confidence = $fieldConfidences[$key] ?? null;
            $confidence ??= $pageConfidence;

            $weakest = $weakest === null ? $confidence : min($weakest, $confidence);
        }

        return max(0, min(100, (int) round((float) $weakest)));
    }

    /**
     * @return list<string>
     */
    private function manuallyDeclaredKeyFields(OriginalDocument $original): array
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

    private function ocrConfidenceForRange(OriginalDocument $original, int $startPage, int $endPage): float
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

    /**
     * Esito della revisione: sopra soglia si valida da solo, salvo il codice
     * fiscale, che ha una soglia propria e piu' alta.
     *
     * La soglia dedicata si applica solo quando un codice fiscale c'e' ed e'
     * rintracciabile: un documento che non lo porta non deve essere penalizzato
     * per un dato che non gli compete.
     *
     * @param  array<string, float|null>  $fieldConfidences
     */
    private function reviewStatusForConfidence(?int $confidenceScore, array $fieldConfidences = []): ReviewStatus
    {
        if ($confidenceScore === null || $confidenceScore < $this->confidenceThreshold) {
            return ReviewStatus::NeedsReview;
        }

        $fiscalCodeConfidence = $fieldConfidences['fiscal_code'] ?? null;

        if ($fiscalCodeConfidence !== null && $fiscalCodeConfidence < $this->fiscalCodeConfidenceThreshold) {
            return ReviewStatus::NeedsReview;
        }

        return ReviewStatus::AutoValidated;
    }

    /**
     * Righe OCR dell'intervallo di pagine del sotto-documento, con la loro
     * confidenza.
     *
     * @return array<int, array{text: string, confidence: float|null}>
     */
    private function ocrBlocksForRange(OriginalDocument $original, int $startPage, int $endPage): array
    {
        $blocks = [];

        foreach ($original->ocrPages as $page) {
            $number = (int) ($page['page'] ?? 0);

            if ($number < $startPage || $number > $endPage) {
                continue;
            }

            foreach ($page['blocks'] ?? [] as $block) {
                $blocks[] = [
                    'text' => (string) ($block['text'] ?? ''),
                    'confidence' => isset($block['confidence']) ? (float) $block['confidence'] : null,
                ];
            }
        }

        return $blocks;
    }

    /**
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, recipient_email: ?string, fiscal_code: ?string, employee_id: ?string, confidence_score: ?int}
     */
    private function extractFields(SubDocument $subDocument, OriginalDocument $original): array
    {
        $ocrText = $this->ocrRange->textForRange($original, $subDocument->startPage, $subDocument->endPage, $this->ocrRange->nonce());

        if ($ocrText === '') {
            throw new \RuntimeException('Testo OCR non disponibile per l\'intervallo di pagine del destinatario.');
        }

        return $this->sanitizeIdentifiers($this->ai->extractFields($ocrText));
    }

    /**
     * Codice fiscale, email e matricola arrivano dal modello come qualunque
     * altro campo, ma sono identificativi: un valore dalla forma sbagliata non
     * e' un'estrazione imprecisa, e' un dato falso che l'operatore non ha modo
     * di riconoscere come tale. Chi non supera il proprio controllo formale
     * torna quindi a null — il campo resta vuoto e compilabile a mano, che e'
     * il comportamento che aveva prima.
     *
     * La matricola non ha una forma dichiarata: varia da azienda ad azienda,
     * quindi passa cosi' com'e'.
     *
     * @param  array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, recipient_email: ?string, fiscal_code: ?string, employee_id: ?string, confidence_score: ?int}  $fields
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, recipient_email: ?string, fiscal_code: ?string, employee_id: ?string, confidence_score: ?int}
     */
    private function sanitizeIdentifiers(array $fields): array
    {
        $email = $fields['recipient_email'] ?? null;
        $fields['recipient_email'] = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? strtolower($email)
            : null;

        $fiscalCode = $fields['fiscal_code'] ?? null;
        $fields['fiscal_code'] = is_string($fiscalCode) && CodiceFiscale::isValid(strtoupper($fiscalCode))
            ? strtoupper($fiscalCode)
            : null;

        $fields['employee_id'] ??= null;

        return $fields;
    }

    /**
     * @param  array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, recipient_email: ?string, fiscal_code: ?string, employee_id: ?string, confidence_score: ?int}  $fields
     * @return array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, recipient_email: ?string, fiscal_code: ?string, employee_id: ?string, confidence_score: ?int}
     */
    private function applyManualMetadataOverrides(array $fields, OriginalDocument $original): array
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
