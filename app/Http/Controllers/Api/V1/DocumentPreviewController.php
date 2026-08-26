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
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $document->filename).'"',
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
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $document->filename).'"',
        ]);
    }
}
