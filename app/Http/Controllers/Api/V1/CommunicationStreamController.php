<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesCommunications;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Models\Communication;
use App\Mvp\Communications\Domain\Ports\Inbound\PollCommunicationProgressUseCase;
use App\Mvp\Communications\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Enums\CoverImageStatus;
use App\Mvp\Support\MvpStateService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stream SSE dell'avanzamento della generazione. Legge lo stato tramite
 * PollCommunicationProgressUseCase (porta primaria): il controller resta
 * responsabile solo del protocollo SSE e dello shaping via MvpStateService
 * (infrastruttura condivisa fuori perimetro), ricaricando dal modello
 * Eloquent solo quando deve emettere l'evento 'cover'/'done' che richiede
 * l'intero aggregato.
 */
class CommunicationStreamController
{
    use AuthorizesCommunications, ResolvesActor;

    /**
     * @throws AuthorizationException
     */
    public function stream(Request $request, Communication $communication, MvpStateService $state, PollCommunicationProgressUseCase $poll): StreamedResponse
    {
        $actor = $this->actor($request);
        $this->assertCommunicationOwnership($communication, $actor);
        $communicationId = $communication->id;

        return response()->stream(function () use ($communicationId, $actor, $state, $poll): void {
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
                try {
                    $snapshot = $poll->poll($communicationId);
                } catch (\Throwable) {
                    $send('error', ['message' => 'Comunicazione non trovata.']);

                    return;
                }

                // Il testo arriva prima della copertina: viene emesso appena
                // disponibile, senza attendere il resto della pipeline.
                if (! $textSent && $snapshot->generatedBody) {
                    $textSent = true;
                    $send('text', [
                        'title' => $snapshot->generatedTitle,
                        'body' => $snapshot->generatedBody,
                    ]);
                }

                if (! $coverSent && ! in_array($snapshot->coverStatus, [CoverImageStatus::Pending->value, CoverImageStatus::Processing->value], true)) {
                    $coverSent = true;
                    // L'URL della copertina richiede il modello Eloquent (route +
                    // hash del path per l'invalidazione cache, vedi MvpStateService):
                    // unico punto di questo stream in cui si ricarica l'aggregato.
                    $fresh = Communication::query()->findOrFail($communicationId);
                    $send('cover', [
                        'coverImageUrl' => $state->coverImageUrl($fresh),
                        'coverStatus' => $snapshot->coverStatus,
                        'coverError' => $snapshot->coverError,
                    ]);
                }

                $signature = $snapshot->generationStatus.':'.$snapshot->coverStatus;

                if ($signature !== $lastSignature) {
                    $send('progress', [
                        'generationStatus' => $snapshot->generationStatus,
                        'coverStatus' => $snapshot->coverStatus,
                    ]);
                    $lastSignature = $signature;
                } else {
                    $heartbeat();
                }

                if ($snapshot->generationStatus === CommunicationGenerationStatus::Completed->value) {
                    $fresh = Communication::query()->findOrFail($communicationId);
                    $send('done', [
                        'communication' => $state->communication($fresh),
                        'state' => $state->forActor($actor),
                    ]);

                    return;
                }

                if ($snapshot->generationStatus === CommunicationGenerationStatus::Failed->value) {
                    $send('error', ['message' => $snapshot->errorMessage ?: 'Generazione non disponibile.']);

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
