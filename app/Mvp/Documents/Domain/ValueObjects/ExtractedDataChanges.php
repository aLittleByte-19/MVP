<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

/**
 * Campi estratti (o corretti manualmente) da salvare per un sotto-documento,
 * costruiti un campo alla volta invece che come array associativo con
 * chiavi a stringa (vedi ADR 0010 e OriginalDocumentChanges, incluso il
 * criterio per le chiavi camelCase). Usata sia dall'estrazione AI (tutti i
 * campi) sia dalla correzione manuale dell'operatore (solo i campi corretti).
 */
final class ExtractedDataChanges
{
    /**
     * Le uniche chiavi che {@see self::fromRawFields()} accetta: i campi
     * corretti a mano dalla revisione umana (UC-9bis/UC-52). `fieldConfidences`
     * e `aiPayload` restano fuori: li scrive solo l'estrazione AI tramite i
     * rispettivi `with*()`, mai un payload HTTP grezzo — difesa in profondita'
     * anche se oggi l'unico chiamante filtra gia' a monte questi stessi campi.
     *
     * @var list<string>
     */
    private const REVIEWABLE_FIELDS = [
        'employeeFirstName',
        'employeeLastName',
        'companyName',
        'documentDate',
        'documentType',
        'description',
        'confidenceScore',
        'recipientEmail',
        'fiscalCode',
        'employeeId',
    ];

    /** @var array<string, mixed> */
    private array $attributes = [];

    private function __construct() {}

    public static function none(): self
    {
        return new self;
    }

    /**
     * Traduce i campi gia' in snake_case ricevuti da ReviewDocumentUseCase
     * (arrivano cosi' dal livello HTTP, vedi il suo docblock): unico punto
     * di conversione da array grezzo, non un pattern da riusare altrove.
     *
     * @param  array<string, mixed>  $fieldUpdates
     *
     * @throws \InvalidArgumentException se una chiave non e' fra i campi
     *                                   correggibili manualmente (vedi {@see self::REVIEWABLE_FIELDS}).
     */
    public static function fromRawFields(array $fieldUpdates): self
    {
        $instance = new self;

        foreach ($fieldUpdates as $key => $value) {
            $camelKey = self::toCamelCase($key);

            if (! in_array($camelKey, self::REVIEWABLE_FIELDS, true)) {
                throw new \InvalidArgumentException("Campo non correggibile manualmente: {$key}.");
            }

            $instance->attributes[$camelKey] = $value;
        }

        return $instance;
    }

    public function withEmployeeFirstName(?string $value): self
    {
        return $this->with('employeeFirstName', $value);
    }

    public function withEmployeeLastName(?string $value): self
    {
        return $this->with('employeeLastName', $value);
    }

    public function withCompanyName(?string $value): self
    {
        return $this->with('companyName', $value);
    }

    public function withDocumentDate(?string $value): self
    {
        return $this->with('documentDate', $value);
    }

    public function withRecipientEmail(?string $value): self
    {
        return $this->with('recipientEmail', $value);
    }

    public function withFiscalCode(?string $value): self
    {
        return $this->with('fiscalCode', $value);
    }

    public function withEmployeeId(?string $value): self
    {
        return $this->with('employeeId', $value);
    }

    public function withDocumentType(?string $value): self
    {
        return $this->with('documentType', $value);
    }

    public function withDescription(?string $value): self
    {
        return $this->with('description', $value);
    }

    public function withConfidenceScore(?int $value): self
    {
        return $this->with('confidenceScore', $value);
    }

    /**
     * Confidenza OCR di ciascun campo trascritto, per chiave in snake_case.
     * Null come valore significa campo non rintracciabile fra le righe OCR,
     * che e' diverso da campo letto male (vedi ADR 0013).
     *
     * @param  array<string, float|null>|null  $confidences
     */
    public function withFieldConfidences(?array $confidences): self
    {
        return $this->with('fieldConfidences', $confidences);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function withAiPayload(?array $payload): self
    {
        return $this->with('aiPayload', $payload);
    }

    private function with(string $attribute, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$attribute] = $value;

        return $clone;
    }

    private static function toCamelCase(string $snakeCase): string
    {
        return lcfirst(str_replace('_', '', ucwords($snakeCase, '_')));
    }

    /**
     * @return list<string>
     */
    public function changedFields(): array
    {
        return array_keys($this->attributes);
    }

    /**
     * @internal consumato solo dall'adapter di persistenza.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }
}
