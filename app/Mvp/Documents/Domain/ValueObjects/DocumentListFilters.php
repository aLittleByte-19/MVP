<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

/**
 * Criteri di filtro dello storico sotto-documenti (UC-35..UC-38).
 * Costruito dall'adapter HTTP a partire dalla Form Request validata:
 * il dominio non vede chiavi HTTP grezze.
 */
final class DocumentListFilters
{
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?string $sendStatus = null,
        public readonly ?int $confidenceThreshold = null,
        public readonly ?string $confidenceCriterion = null,
        public readonly ?int $month = null,
        public readonly ?int $year = null,
    ) {}
}
