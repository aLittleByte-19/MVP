<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// I servizi sotto unit test usano helper Laravel (resource_path, facades):
// serve il bootstrap dell'applicazione anche qui, senza database refresh.
pest()->extend(TestCase::class)
    ->in('Unit');

// Nessun ->extend(): questi test istanziano casi d'uso e value object di
// dominio direttamente, con adapter di test (tests/DomainUnit/**/Fakes/) al
// posto delle porte secondarie — zero bootstrap Laravel, zero DB, zero AWS
// (refactory.md, Compito 3 punto 2; ADR 0010).
pest()->in('DomainUnit');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
