<?php

namespace App\Http\Controllers\System;

use App\Copilot\Observability\PrometheusExporter;
use Illuminate\Http\Response;

class InternalMetricsController
{
    public function __invoke(PrometheusExporter $exporter): Response
    {
        return response($exporter->render(), 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
