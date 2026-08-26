<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Mvp\Support\AssistantMetricsReportRenderer;
use App\Mvp\Support\MvpStateService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Report riepilogativo delle metriche dell'AI Assistant, come allegato PDF
 * (RF34-OB, UC-27).
 */
class AssistantMetricsReportController
{
    use ResolvesActor;

    public function __invoke(Request $request, MvpStateService $state, AssistantMetricsReportRenderer $renderer): Response
    {
        $assistantState = $state->assistantState($this->actor($request));
        $bytes = $renderer->render($assistantState);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$renderer->filename().'"',
        ]);
    }
}
