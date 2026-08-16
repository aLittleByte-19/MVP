<?php

namespace App\Mvp\Support\Persistence;

/**
 * Confine transazionale per operazioni che richiedono piu' scritture atomiche
 * sullo stesso aggregato. Adapter secondario condiviso fra Documents e
 * Communications: come l'orologio PSR-20 e il generatore di id (vedi
 * App\Mvp\Support\Clock, App\Mvp\Support\Identifiers), la transazionalita' non
 * ha semantica di dominio — e' un dettaglio di persistenza, non una decisione
 * che un caso d'uso deve prendere (vedi ADR 0010).
 */
interface TransactionManagerPort
{
    /**
     * Esegue $operation dentro un'unica transazione: se lancia, nessuna delle
     * scritture al suo interno viene applicata. Da usare solo per sequenze di
     * scritture pure — mai per racchiudere chiamate di rete lente (AI,
     * storage), che terrebbero una transazione aperta troppo a lungo.
     *
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
