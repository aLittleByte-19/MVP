<?php

namespace App\Mvp\Documents\Domain\ValueObjects;

/**
 * Pagina di risultati di una ricerca sui sotto-documenti: solo identificativi
 * e metadati di paginazione. La forma di presentazione HTTP (che richiede le
 * relazioni Eloquent caricate) resta responsabilita' dell'adapter primario,
 * che ri-carica gli id restituiti qui — la ricerca/filtro, che e' la parte
 * decisionale, passa comunque interamente dalla porta.
 */
final class SubDocumentPage
{
    /**
     * @param  list<int>  $subDocumentIds  Ordine di rilevanza gia' applicato (piu' recenti prima).
     */
    public function __construct(
        public readonly array $subDocumentIds,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {}
}
