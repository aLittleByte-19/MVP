<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesCommunications;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Models\Communication;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotReadyForExportException;
use App\Mvp\Communications\Domain\Ports\Inbound\ExportCommunicationUseCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Documento finale impaginato: anteprima inline ed esportazione come allegato.
 */
class CommunicationExportController
{
    use AuthorizesCommunications, ResolvesActor;

    /**
     * @throws AuthorizationException
     */
    public function preview(Request $request, Communication $communication, ExportCommunicationUseCase $export): Response
    {
        $this->assertCommunicationOwnership($communication, $this->actor($request));

        return $this->pdfResponse($request, $communication, $export, inline: true);
    }

    /**
     * @throws AuthorizationException
     */
    public function export(Request $request, Communication $communication, ExportCommunicationUseCase $export): Response
    {
        $this->assertCommunicationOwnership($communication, $this->actor($request));

        return $this->pdfResponse($request, $communication, $export, inline: false);
    }

    /**
     * L'ETag e' il fingerprint del contenuto: finche' titolo, corpo e copertina
     * non cambiano il browser si riprende il PDF dalla propria cache con un 304
     * e il renderer non viene nemmeno interpellato.
     */
    private function pdfResponse(
        Request $request,
        Communication $communication,
        ExportCommunicationUseCase $export,
        bool $inline,
    ): Response {
        try {
            $fingerprint = $export->fingerprint($communication->id);
        } catch (CommunicationNotReadyForExportException $e) {
            throw ValidationException::withMessages(['communication' => [$e->getMessage()]]);
        }

        $etag = '"'.$fingerprint.'"';
        $knownEtags = $request->getETags();

        if (in_array($etag, $knownEtags, true) || in_array('*', $knownEtags, true)) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=0, must-revalidate',
            ]);
        }

        $rendered = $export->render($communication->id);
        $disposition = $inline ? 'inline' : 'attachment; filename="'.str_replace('"', '', $rendered->filename).'"';

        return response($rendered->bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }
}
