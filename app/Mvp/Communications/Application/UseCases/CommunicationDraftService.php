<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Entities\Communication;
use App\Mvp\Communications\Domain\Events\CommunicationDraftApproved;
use App\Mvp\Communications\Domain\Events\CommunicationDraftDiscarded;
use App\Mvp\Communications\Domain\Events\CommunicationDraftEdited;
use App\Mvp\Communications\Domain\Events\CommunicationDraftFavorited;
use App\Mvp\Communications\Domain\Events\CommunicationDraftUnfavorited;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotAuthorizedException;
use App\Mvp\Communications\Domain\Ports\Inbound\CommunicationDraftUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Support\Identity\Actor;
use App\Mvp\Support\Persistence\TransactionManagerPort;

/**
 * Ogni metodo ricontrolla il tenant dell'attore contro quello della
 * comunicazione caricata ({@see self::assertOwnership()}): il controllo
 * HTTP protegge solo chi lo chiama, il caso d'uso deve restare sicuro anche
 * invocato da un adapter primario futuro che se ne dimentichi.
 */
class CommunicationDraftService implements CommunicationDraftUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationEventDispatcherPort $events,
        private readonly TransactionManagerPort $transactions,
    ) {}

    public function favorite(int $communicationId, Actor $actor): void
    {
        $this->transactions->run(function () use ($communicationId, $actor): void {
            $communication = $this->communications->findCommunicationForUpdate($communicationId);
            $this->assertOwnership($communication, $actor);
            $communication->favorite();
            $this->communications->saveCommunication($communication);
        });

        $this->events->dispatch(new CommunicationDraftFavorited($communicationId, $actor));
    }

    public function unfavorite(int $communicationId, Actor $actor): void
    {
        $this->transactions->run(function () use ($communicationId, $actor): void {
            $communication = $this->communications->findCommunicationForUpdate($communicationId);
            $this->assertOwnership($communication, $actor);
            $communication->unfavorite();
            $this->communications->saveCommunication($communication);
        });

        $this->events->dispatch(new CommunicationDraftUnfavorited($communicationId, $actor));
    }

    public function update(int $communicationId, string $title, string $body, Actor $actor): void
    {
        $this->transactions->run(function () use ($communicationId, $title, $body, $actor): void {
            $communication = $this->communications->findCommunicationForUpdate($communicationId);
            $this->assertOwnership($communication, $actor);
            $communication->updateDraft($title, $body);
            $this->communications->saveCommunication($communication);
        });

        $this->events->dispatch(new CommunicationDraftEdited($communicationId, $actor));
    }

    /**
     * Lock pessimistico sulla riga (come favorite()/unfavorite()): senza, un
     * `discard()` e un `save()` concorrenti potrebbero superare entrambi il
     * proprio controllo di stato e l'ultimo che scrive vince — una bozza
     * appena scartata puo' finire "approvata" per puro timing.
     */
    public function save(int $communicationId, Actor $actor): void
    {
        $this->transactions->run(function () use ($communicationId, $actor): void {
            $communication = $this->communications->findCommunicationForUpdate($communicationId);
            $this->assertOwnership($communication, $actor);
            $communication->approve();
            $this->communications->saveCommunication($communication);
        });

        $this->events->dispatch(new CommunicationDraftApproved($communicationId, $actor));
    }

    /** @see self::save() per il motivo del lock. */
    public function discard(int $communicationId, Actor $actor): void
    {
        $this->transactions->run(function () use ($communicationId, $actor): void {
            $communication = $this->communications->findCommunicationForUpdate($communicationId);
            $this->assertOwnership($communication, $actor);
            $communication->discard();
            $this->communications->saveCommunication($communication);
        });

        $this->events->dispatch(new CommunicationDraftDiscarded($communicationId, $actor));
    }

    private function assertOwnership(Communication $communication, Actor $actor): void
    {
        if ($communication->tenantId !== $actor->tenantId) {
            throw new CommunicationNotAuthorizedException;
        }
    }
}
