<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesCommunications;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Http\Requests\UpdateCommunicationCoverRequest;
use App\Models\Communication;
use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Exceptions\CommunicationCoverUnavailableException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Communications\Domain\Ports\Inbound\DownloadCommunicationCoverUseCase;
use App\Mvp\Communications\Domain\Ports\Inbound\UpdateCommunicationCoverUseCase;
use App\Mvp\Support\MvpStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Adapter primario HTTP: immagine di copertina della comunicazione
 * (sostituzione manuale, rimozione e download). L'audit della mutazione resta
 * qui perche' dipende da metadati solo HTTP (mime/size del file caricato),
 * non da una regola di dominio (vedi ADR 0010). Il download passa dalla
 * porta primaria DownloadCommunicationCoverUseCase, stesso schema di
 * DocumentPreviewController.
 */
class CommunicationCoverController
{
    use AuthorizesCommunications, ResolvesActor;

    /**
     * @throws AuthorizationException
     */
    public function updateCoverImage(
        UpdateCommunicationCoverRequest $request,
        Communication $communication,
        UpdateCommunicationCoverUseCase $covers,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        /** @var UploadedFile $file */
        $file = $request->file('image');
        $path = $file->getRealPath();
        $bytes = $path !== false ? file_get_contents($path) : false;

        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Immagine di copertina non leggibile.');
        }

        $mime = $file->getMimeType() ?: 'image/png';

        try {
            $covers->update($communication->id, $bytes, $mime, strlen($bytes), $actor);
        } catch (CommunicationNotEditableException $e) {
            throw ValidationException::withMessages(['communication' => [$e->getMessage()]]);
        }

        $audit->record(
            'mvp-communication-cover-updated',
            $actor,
            'communication',
            (string) $communication->id,
            ['mime' => $mime, 'size' => strlen($bytes)],
            $request,
        );

        return response()->json([
            'message' => 'Immagine di copertina aggiornata correttamente.',
            'communication' => $state->communication($communication->refresh()),
            'state' => $state->forActor($actor),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function removeCoverImage(
        Request $request,
        Communication $communication,
        UpdateCommunicationCoverUseCase $covers,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        try {
            $covers->remove($communication->id, $actor);
        } catch (CommunicationNotEditableException $e) {
            throw ValidationException::withMessages(['communication' => [$e->getMessage()]]);
        }

        $audit->record(
            'mvp-communication-cover-removed',
            $actor,
            'communication',
            (string) $communication->id,
            [],
            $request,
        );

        return response()->json([
            'message' => 'Immagine di copertina rimossa.',
            'communication' => $state->communication($communication->refresh()),
            'state' => $state->forActor($actor),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function coverImage(Request $request, Communication $communication, DownloadCommunicationCoverUseCase $covers): Response
    {
        $this->assertCommunicationOwnership($communication, $this->actor($request));

        try {
            $cover = $covers->download($communication->id);
        } catch (CommunicationCoverUnavailableException $exception) {
            abort(404, $exception->getMessage());
        } catch (\RuntimeException $exception) {
            report($exception);

            abort(503, 'Storage copertine non raggiungibile.');
        }

        return response($cover->bytes, 200, [
            'Content-Type' => $cover->mime,
            'Content-Disposition' => 'inline',
            // Il percorso e' stabile e distingue le versioni solo con il
            // parametro "v": la risposta va rivalidata, altrimenti una
            // sostituzione resterebbe invisibile fino alla scadenza della cache.
            'Cache-Control' => 'private, no-cache, must-revalidate',
        ]);
    }
}
