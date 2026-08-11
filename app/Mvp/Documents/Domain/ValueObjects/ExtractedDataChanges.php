<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

/**
 * Campi estratti (o corretti manualmente) da salvare per un sotto-documento,
 * costruiti un campo alla volta invece che come array associativo con
 * chiavi a stringa (vedi ADR 0010 e OriginalDocumentChanges). Usata sia
 * dall'estrazione AI (tutti i campi) sia dalla correzione manuale
 * dell'operatore (solo i campi corretti).
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
        $instance->attributes = $fieldUpdates;

        return $instance;
    }

    public function withEmployeeFirstName(?string $value): self
    {
        return $this->with('employee_first_name', $value);
    }

    public function withEmployeeLastName(?string $value): self
    {
        return $this->with('employee_last_name', $value);
    }

    public function withCompanyName(?string $value): self
    {
        return $this->with('company_name', $value);
    }

    public function withDocumentDate(?string $value): self
    {
        return $this->with('document_date', $value);
    }

    public function withDocumentType(?string $value): self
    {
        return $this->with('document_type', $value);
    }

    public function withDescription(?string $value): self
    {
        return $this->with('description', $value);
    }

    public function withConfidenceScore(?int $value): self
    {
        return $this->with('confidence_score', $value);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function withAiPayload(?array $payload): self
    {
        return $this->with('ai_payload', $payload);
    }

    private function with(string $attribute, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$attribute] = $value;

        return $clone;
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
