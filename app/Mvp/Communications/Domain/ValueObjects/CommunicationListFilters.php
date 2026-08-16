<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

/**
 * Criteri di filtro dello storico comunicazioni (UC-15..UC-18).
 * Costruito dall'adapter HTTP a partire dalla Form Request validata.
 */
final class CommunicationListFilters
{
    public function __construct(
        public readonly ?string $keyword = null,
        public readonly ?string $tone = null,
        public readonly ?string $style = null,
        public readonly ?string $date = null,
    ) {}
}
