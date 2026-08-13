<?php

namespace App\Mvp\Communications\Domain\ValueObjects;

/**
 * Pagina di risultati di una ricerca sulle comunicazioni approvate: solo
 * identificativi e metadati di paginazione, stesso taglio dell'equivalente
 * VO nel dominio Documents. La forma di presentazione HTTP (che richiede le
 * relazioni Eloquent caricate) resta responsabilita' dell'adapter primario,
 * che ri-carica gli id restituiti qui — la ricerca/filtro, che e' la parte
 * decisionale, passa comunque interamente dalla porta.
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
