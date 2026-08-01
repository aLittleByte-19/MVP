<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesCommunications;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Http\Requests\UpdateCommunicationCoverRequest;
use App\Models\Communication;
use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Services\CommunicationCoverService;
use App\Mvp\Support\MvpStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Immagine di copertina della comunicazione: sostituzione manuale, rimozione e download.
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
        CommunicationCoverService $covers,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        /** @var UploadedFile $file */
        $file = $request->file('image');
        $covers->storeUploaded($communication, $file);

        $audit->record(
            'mvp-communication-cover-updated',
            $actor,
            'communication',
            (string) $communication->id,
            [
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
            ],
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
        CommunicationCoverService $covers,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        $covers->remove($communication);

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
    public function coverImage(Request $request, Communication $communication): StreamedResponse
    {
        $this->assertCommunicationOwnership($communication, $this->actor($request));

        $path = $communication->cover_image_path;
        abort_if($path === null || $path === '', 404);

        $disk = Storage::disk((string) config('mvp.communications.cover_disk', config('filesystems.default', 'local')));

        try {
            abort_unless($disk->exists($path), 404);
        } catch (FilesystemException $exception) {
            report($exception);

            abort(503, 'Storage copertine non raggiungibile.');
        }

        return response()->stream(function () use ($disk, $path): void {
            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                return;
            }

            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $communication->cover_image_mime ?: 'image/png',
            'Content-Disposition' => 'inline',
            // Il percorso e' stabile e distingue le versioni solo con il
            // parametro "v": la risposta va rivalidata, altrimenti una
            // sostituzione resterebbe invisibile fino alla scadenza della cache.
            'Cache-Control' => 'private, no-cache, must-revalidate',
        ]);
    }
}
