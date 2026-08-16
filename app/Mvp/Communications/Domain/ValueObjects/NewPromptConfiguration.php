<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

/**
 * Dati per creare un preset di prompt. Stesso taglio di NewCommunication:
 * tutti i campi noti alla creazione, costruttore semplice.
 */
final class NewPromptConfiguration
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $createdBy,
        public readonly string $name,
        public readonly string $prompt,
        public readonly string $tone,
        public readonly string $style,
    ) {}

    /**
     * @internal consumato solo dall'adapter di persistenza.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'created_by' => $this->createdBy,
            'name' => $this->name,
            'prompt' => $this->prompt,
            'tone' => $this->tone,
            'style' => $this->style,
        ];
    }
}
