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
     */
    public static function fromRawFields(array $fieldUpdates): self
    {
        $instance = new self;

        foreach ($fieldUpdates as $key => $value) {
            $instance->attributes[self::toCamelCase($key)] = $value;
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
