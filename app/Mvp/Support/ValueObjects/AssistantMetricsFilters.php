<?php

namespace App\Mvp\Support\ValueObjects;

/**
 * Filtri della sezione Metriche dell'AI Assistant (RF38-OB..RF41-OB): tono e
 * stile non esistono sui documenti del Co-Pilot, quindi non ha senso
 * filtrarne i numeri con questi campi. Costruito dall'adapter HTTP a partire
 * dalla Form Request validata, stesso schema di CommunicationListFilters.
 */
final class AssistantMetricsFilters
{
    public function __construct(
        public readonly ?string $tone = null,
        public readonly ?string $style = null,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
    ) {}
}
