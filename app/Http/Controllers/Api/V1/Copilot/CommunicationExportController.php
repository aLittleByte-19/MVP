<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Copilot\Communications\Services\CommunicationPdfService;
use App\Http\Controllers\Api\V1\Copilot\Concerns\AuthorizesCommunications;
use App\Http\Controllers\Api\V1\Copilot\Concerns\ResolvesActor;
use App\Models\Copilot\Communication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Documento finale impaginato: anteprima inline ed esportazione come allegato.
 */
class CommunicationExportController
{
    use AuthorizesCommunications, ResolvesActor;

    /**
     * @throws AuthorizationException
     */
    public function preview(Request $request, Communication $communication, CommunicationPdfService $pdf): Response
    {
        $this->assertCommunicationOwnership($communication, $this->actor($request));
        $this->assertCommunicationReadyForExport($communication);

        return $this->pdfResponse($request, $communication, $pdf, 'inline');
    }

    /**
     * @throws AuthorizationException
     */
    public function export(Request $request, Communication $communication, CommunicationPdfService $pdf): Response
    {
        $this->assertCommunicationOwnership($communication, $this->actor($request));
        $this->assertCommunicationReadyForExport($communication);

        return $this->pdfResponse(
            $request,
            $communication,
            $pdf,
            'attachment; filename="'.str_replace('"', '', $pdf->filename($communication)).'"',
        );
    }

    /**
     * L'ETag e' il fingerprint del contenuto: finche' titolo, corpo e copertina
     * non cambiano il browser si riprende il PDF dalla propria cache con un 304
     * e dompdf non viene nemmeno interpellato.
     */
    private function pdfResponse(
        Request $request,
        Communication $communication,
        CommunicationPdfService $pdf,
        string $disposition,
    ): Response {
        $etag = '"'.$pdf->fingerprint($communication).'"';
        $knownEtags = $request->getETags();

        if (in_array($etag, $knownEtags, true) || in_array('*', $knownEtags, true)) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=0, must-revalidate',
            ]);
        }

        return response($pdf->render($communication), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }
}
