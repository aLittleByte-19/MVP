<?php

use App\Exceptions\AiServiceException;
use App\Models\Communication;

/**
 * Lo stream SSE dell'avanzamento della generazione. Il corpo del ciclo non
 * gira mai qui: `assertOk()`/`assertForbidden()`/`assertJsonPath()` leggono
 * solo status/header, senza invocare `sendContent()`.
 */
test('the stream opens with the headers required by server sent events', function () {
    $communication = Communication::factory()->create();

    $response = $this->get("/api/v1/communications/{$communication->id}/stream")->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream')
        // Senza no-cache un proxy servirebbe una risposta vecchia e
        // l'avanzamento resterebbe fermo; X-Accel-Buffering evita che nginx
        // accumuli gli eventi invece di inoltrarli.
        ->and($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no');
});

test('the stream rejects a communication belonging to another tenant', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $communication = Communication::factory()->create();

    $this->get("/api/v1/communications/{$communication->id}/stream", [
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

test('the stream of a communication that does not exist is a not found', function () {
    $this->get('/api/v1/communications/999999/stream')->assertNotFound();
});

test('the ai service exception carries a bad gateway code by default', function () {
    // 502 e non 500: il guasto e' del servizio a monte, non dell'applicazione,
    // e la distinzione conta per gli alert.
    $exception = new AiServiceException;

    expect($exception->getMessage())->toBe('Servizio AI non disponibile.')
        ->and($exception->getCode())->toBe(502);
});

test('the ai service exception keeps the original failure as its cause', function () {
    $cause = new RuntimeException('Bedrock ha risposto 429');

    $exception = new AiServiceException('Generazione non disponibile.', 503, $cause);

    expect($exception->getMessage())->toBe('Generazione non disponibile.')
        ->and($exception->getCode())->toBe(503)
        ->and($exception->getPrevious())->toBe($cause);
});
