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

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});
