<?php

namespace App\Mvp\Support\Identifiers;

/**
 * Genera identificatori univoci casuali. Adapter secondario condiviso fra
 * Documents e Communications: come l'orologio PSR-20 (vedi
 * App\Mvp\Support\Clock), generare un id univoco non ha alcuna semantica di
 * dominio — due implementazioni identiche sarebbero la stessa duplicazione
 * decorativa che WorkflowEnginePort evita per Step Functions (vedi ADR 0010).
 * Nessuno standard PSR esiste per questo (a differenza di Clock/Log), quindi
 * l'interfaccia e' definita qui invece di riusarne una esterna.
 */
interface UniqueIdGeneratorPort
{
    public function generate(): string;
}
