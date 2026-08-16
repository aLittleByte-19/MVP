<?php

namespace App\Mvp\Support\Clock;

use Psr\Clock\ClockInterface;

/**
 * Adapter secondario condiviso fra Documents e Communications: a differenza
 * degli eventi di dominio (specifici per dominio, vedi ADR 0010), il tempo
 * non ha alcuna semantica di dominio — creare due implementazioni identiche
 * sarebbe la stessa duplicazione decorativa che WorkflowEnginePort evita per
 * Step Functions. Implementa lo standard PSR-20 (`psr/clock`), non
 * un'interfaccia custom: stesso trattamento gia' riservato a `Psr\Log\LoggerInterface`.
 * Laravel imposta il timezone di PHP da `config('app.timezone')` all'avvio,
 * quindi `new \DateTimeImmutable()` produce lo stesso istante/timezone che
 * l'helper `now()` avrebbe prodotto.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable;
    }
}
