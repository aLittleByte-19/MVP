<?php

namespace App\Mvp\Observability;

use Illuminate\Foundation\Http\Events\RequestHandled;

/**
 * Emette una riga di metrica EMF per richiesta (numero, latenza, errori).
 * Ascolta RequestHandled invece di essere un middleware: un'eccezione che
 * risale la pipeline non eseguirebbe mai il codice dopo $next($request), mentre
 * RequestHandled arriva dopo che il Kernel l'ha gia' tradotta in risposta, cosi'
 * $event->response ha sempre lo status finale — anche quello di un errore. Vedi ADR 0015.
 */
class RecordRequestMetrics
{
    public function __construct(private readonly EmfMetricsRecorder $metrics) {}

    public function handle(RequestHandled $event): void
    {
        $request = $event->request;
        $response = $event->response;
        $start = (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

        $this->metrics->put(
            dimensions: [
                'Route' => $request->route()?->getName() ?? $request->path(),
                'Method' => $request->method(),
            ],
            metrics: [
                'RequestCount' => ['value' => 1, 'unit' => 'Count'],
                'Latency' => ['value' => (microtime(true) - $start) * 1000, 'unit' => 'Milliseconds'],
                'Errors' => ['value' => $response->getStatusCode() >= 500 ? 1 : 0, 'unit' => 'Count'],
            ],
            properties: [
                'request_id' => $request->attributes->get('request_id'),
            ],
        );
    }
}
