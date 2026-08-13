<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Events\CommunicationDraftApproved;
use App\Mvp\Communications\Domain\Events\CommunicationDraftDiscarded;
use App\Mvp\Communications\Domain\Events\CommunicationDraftEdited;
use App\Mvp\Communications\Domain\Events\CommunicationDraftFavorited;
use App\Mvp\Communications\Domain\Events\CommunicationDraftUnfavorited;
use App\Mvp\Communications\Domain\Ports\Inbound\CommunicationDraftUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Support\Identity\Actor;

class CommunicationDraftService implements CommunicationDraftUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationEventDispatcherPort $events,
    ) {}

    public function favorite(int $communicationId, Actor $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        $communication->favorite();
        $this->communications->saveCommunication($communication);

        $this->events->dispatch(new CommunicationDraftFavorited($communicationId, $actor));
    }

    public function unfavorite(int $communicationId, Actor $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        $communication->unfavorite();
        $this->communications->saveCommunication($communication);

        $this->events->dispatch(new CommunicationDraftUnfavorited($communicationId, $actor));
    }

    public function update(int $communicationId, string $title, string $body, Actor $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        $communication->updateDraft($title, $body);
        $this->communications->saveCommunication($communication);

        $this->events->dispatch(new CommunicationDraftEdited($communicationId, $actor));
    }

    public function save(int $communicationId, Actor $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        $communication->approve();
        $this->communications->saveCommunication($communication);

        $this->events->dispatch(new CommunicationDraftApproved($communicationId, $actor));
    }

    public function discard(int $communicationId, Actor $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        $communication->discard();
        $this->communications->saveCommunication($communication);

        $this->events->dispatch(new CommunicationDraftDiscarded($communicationId, $actor));
    }
}
