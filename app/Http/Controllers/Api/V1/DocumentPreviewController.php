<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesDocuments;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Models\SubDocument;
use App\Mvp\Documents\Domain\Exceptions\DocumentPreviewUnavailableException;
use App\Mvp\Documents\Domain\Ports\Inbound\PreviewDocumentUseCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Anteprima PDF del sotto-documento, con errore applicativo leggibile quando lo storage non risponde.
 */
class DocumentPreviewController
{
    use AuthorizesDocuments, ResolvesActor;

    public function preview(Request $request, SubDocument $subDocument, PreviewDocumentUseCase $preview): Response
    {
        $actor = $this->actor($request);
        $this->authorizeSubDocument($subDocument, $actor);

        try {
            $document = $preview->preview($subDocument->id, $actor);
        } catch (DocumentPreviewUnavailableException $exception) {
            abort(404, $exception->getMessage());
        } catch (\RuntimeException $exception) {
            report($exception);

            abort(503, 'Storage documenti non raggiungibile.');
        }

        return response($document->bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->sanitizeFilename($document->filename).'"',
        ]);
    }

    /**
     * Anteprima del documento originale completo, non splittato (UC-40.2/RF56-OB).
     */
    public function originalPreview(Request $request, SubDocument $subDocument, PreviewDocumentUseCase $preview): Response
    {
        $actor = $this->actor($request);
        $this->authorizeSubDocument($subDocument, $actor);

        try {
            $document = $preview->previewOriginal($subDocument->id, $actor);
        } catch (DocumentPreviewUnavailableException $exception) {
            abort(404, $exception->getMessage());
        } catch (\RuntimeException $exception) {
            report($exception);

            abort(503, 'Storage documenti non raggiungibile.');
        }

        return response($document->bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->sanitizeFilename($document->filename).'"',
        ]);
    }

    /**
     * A differenza di `str_replace('"', ...)` (rimuoveva solo le virgolette),
     * toglie anche i caratteri di controllo (compresi CR/LF) dal nome file
     * prima di scriverlo nell'header `Content-Disposition` — la stessa
     * classe di caratteri che renderebbe un header manipolabile. Non usa
     * `Str::slug()` come DompdfCommunicationPdfRenderer/SendMessageService:
     * quei due costruiscono un nome sintetico da un titolo, qui il nome
     * arriva dal file caricato dall'utente (puo' avere spazi/underscore
     * legittimi, vedi UploadDocumentRequest) e uno slug lo snaturerebbe
     * senza un guadagno di sicurezza — CR/LF e virgolette sono gia' gli
     * unici caratteri rilevanti per un header HTTP.
     */
    private function sanitizeFilename(string $filename): string
    {
        $safe = preg_replace('/[\x00-\x1F\x7F"]/', '', $filename);

        return $safe !== null && $safe !== '' ? $safe : 'documento.pdf';
    }
}
