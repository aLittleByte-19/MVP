<?php

namespace App\Mvp\Observability;

use Illuminate\Foundation\Http\Events\RequestHandled;

/**
 * Emette una riga di metrica EMF per richiesta (numero, latenza, errori).
 * Ascolta RequestHandled invece di essere un middleware: un'eccezione che
 * risale la pipeline (validazione, autorizzazione, 500 non gestito) non
 * farebbe mai eseguire il codice dopo $next($request) in un middleware — il
 * Kernel HTTP traduce l'eccezione in risposta FUORI dalla pipeline. RequestHandled
 * viene invece dispatchato da Kernel::handle() dopo quella traduzione, quindi
 * $event->response ha sempre lo status code finale davvero inviato al client,
 * incluso quello di una richiesta fallita — il caso che la metrica Errors deve
 * proprio catturare. Vedi ADR 0015.
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
