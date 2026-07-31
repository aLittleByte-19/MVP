<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesCommunications;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Models\Communication;
use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Enums\CoverImageStatus;
use App\Mvp\Support\MvpStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stream SSE dell'avanzamento della generazione.
 */
class CommunicationStreamController
{
    use AuthorizesCommunications, ResolvesActor;

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
}
