<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

/**
 * Pagina di risultati di una ricerca sulle comunicazioni approvate: solo
 * identificativi e metadati di paginazione. La forma di presentazione HTTP
 * resta responsabilita' dell'adapter primario, che ri-carica gli id
 * restituiti qui — la ricerca/filtro passa comunque interamente dalla porta.
 */
final class CommunicationPage
{
    /**
     * @param  list<int>  $communicationIds  Ordine di rilevanza gia' applicato (piu' recenti prima).
     */
    public function __construct(
        public readonly array $communicationIds,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {}
}
