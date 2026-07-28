<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Communications\Enums\CommunicationGenerationStatus;
use App\Copilot\Communications\Enums\CommunicationStatus;
use App\Copilot\Communications\Enums\CoverImageStatus;
use App\Copilot\Communications\Services\CommunicationCoverService;
use App\Copilot\Communications\Services\CommunicationPdfService;
use App\Copilot\Communications\Services\CommunicationWorkflowService;
use App\Copilot\Identity\MvpUser;
use App\Copilot\Support\MvpStateService;
use App\Http\Requests\Copilot\GenerateCommunicationRequest;
use App\Http\Requests\Copilot\UpdateCommunicationCoverRequest;
use App\Models\Copilot\Communication;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CommunicationController
{
    public function store(
        GenerateCommunicationRequest $request,
        CommunicationWorkflowService $workflow,
        AuditLogger $audit,
    ): JsonResponse {
        $validated = $request->validated();
        $actor = $this->actor($request);

        $communication = Communication::create([
            'tenant_id' => $actor->tenantId,
            'created_by' => $actor->id,
            'prompt' => $validated['prompt'],
            'tone' => $validated['tone'],
            'style' => $validated['style'],
            'generation_status' => CommunicationGenerationStatus::Pending,
            'cover_status' => CoverImageStatus::Pending,
            'status' => CommunicationStatus::Draft,
        ]);

        $audit->record(
            'mvp-communication-generation-requested',
            $actor,
            'communication',
            (string) $communication->id,
            ['tone' => $communication->tone, 'style' => $communication->style],
            $request,
        );

        $workflow->start($communication, $actor, $request);

        return response()->json([
            'message' => 'Generazione avviata.',
            'communicationId' => $communication->id,
            // URL relativo: la SPA e' servita in HTTPS dietro Traefik, che termina
            // il TLS e inoltra in HTTP. Un URL assoluto verrebbe generato con
            // schema "http://" e bloccato dal browser come mixed-content.
            'streamUrl' => route('api.v1.communications.stream', ['communication' => $communication->id], false),
        ], 202);
    }

    /**
     * @throws AuthorizationException
     */
    public function stream(Request $request, Communication $communication, MvpStateService $state): StreamedResponse
    {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);

        return response()->stream(function () use ($communication, $actor, $state): void {
            if (app()->runningUnitTests()) {
                return;
            }

            set_time_limit(0);

            $send = function (string $event, array $data): void {
                echo "event: {$event}\ndata: ".json_encode($data)."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            // Commento SSE: ignorato dal browser ma mantiene viva la connessione
            // quando non ci sono novita' (evita chiusure da idle-timeout dei proxy).
            $heartbeat = function (): void {
                echo ": keepalive\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            $startedAt = time();
            $timeoutSeconds = 300;
            $lastSignature = null;
            $textSent = false;
            $coverSent = false;

            while (! connection_aborted()) {
                $fresh = Communication::query()->find($communication->id);

                if (! $fresh) {
                    $send('error', ['message' => 'Comunicazione non trovata.']);

                    return;
                }

                // Il testo arriva prima della copertina: viene emesso appena
                // disponibile, senza attendere il resto della pipeline.
                if (! $textSent && $fresh->generated_body) {
                    $textSent = true;
                    $send('text', [
                        'title' => $fresh->generated_title,
                        'body' => $fresh->generated_body,
                    ]);
                }

                if (! $coverSent && ! in_array($fresh->cover_status, [CoverImageStatus::Pending, CoverImageStatus::Processing], true)) {
                    $coverSent = true;
                    $send('cover', [
                        'coverImageUrl' => $state->coverImageUrl($fresh),
                        'coverStatus' => $fresh->cover_status->value,
                        'coverError' => $fresh->cover_error,
                    ]);
                }

                $signature = $fresh->generation_status->value.':'.$fresh->cover_status->value;

                if ($signature !== $lastSignature) {
                    $send('progress', [
                        'generationStatus' => $fresh->generation_status->value,
                        'coverStatus' => $fresh->cover_status->value,
                    ]);
                    $lastSignature = $signature;
                } else {
                    $heartbeat();
                }

                if ($fresh->generation_status === CommunicationGenerationStatus::Completed) {
                    $send('done', [
                        'communication' => $state->communication($fresh),
                        'state' => $state->forActor($actor),
                    ]);

                    return;
                }

                if ($fresh->generation_status === CommunicationGenerationStatus::Failed) {
                    $send('error', ['message' => $fresh->error_message ?: 'Generazione non disponibile.']);

                    return;
                }

                if (time() - $startedAt >= $timeoutSeconds) {
                    $send('error', ['message' => 'Timeout generazione.']);

                    return;
                }

                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

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

    private function actor(Request $request): MvpUser
    {
        $actor = $request->user();

        if (! $actor instanceof MvpUser) {
            throw new \RuntimeException('MVP identity middleware did not provide a structured user.');
        }

        return $actor;
    }

    /**
     * @throws AuthorizationException
     */
    private function assertCommunicationOwnership(Communication $communication, MvpUser $actor): void
    {
        if ($communication->tenant_id !== $actor->tenantId) {
            throw new AuthorizationException('Communication is outside the authenticated tenant scope.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertCommunicationReadyForExport(Communication $communication): void
    {
        if (
            $communication->generation_status !== CommunicationGenerationStatus::Completed
            || $communication->status === CommunicationStatus::Discarded
        ) {
            throw ValidationException::withMessages([
                'communication' => ['La bozza non è ancora pronta per l\'anteprima/esportazione.'],
            ]);
        }
    }
}
