<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Events\CommunicationDraftApproved;
use App\Mvp\Communications\Domain\Events\CommunicationDraftDiscarded;
use App\Mvp\Communications\Domain\Events\CommunicationDraftEdited;
use App\Mvp\Communications\Domain\Events\CommunicationDraftFavorited;
use App\Mvp\Communications\Domain\Events\CommunicationDraftUnfavorited;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyDiscardedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyFavoritedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotDraftException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotFavoritedException;
use App\Mvp\Communications\Domain\Ports\Inbound\CommunicationDraftUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationChanges;
use App\Mvp\Communications\Enums\CommunicationStatus;
use App\Mvp\Identity\MvpUser;

class CommunicationDraftService implements CommunicationDraftUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationEventDispatcherPort $events,
    ) {}

    public function favorite(int $communicationId, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->isFavorite) {
            throw new CommunicationAlreadyFavoritedException;
        }

        $this->communications->updateCommunication($communicationId, CommunicationChanges::none()->withIsFavorite(true));
        $this->events->dispatch(new CommunicationDraftFavorited($communicationId, $actor));
    }

    public function unfavorite(int $communicationId, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if (! $communication->isFavorite) {
            throw new CommunicationNotFavoritedException;
        }

        $this->communications->updateCommunication($communicationId, CommunicationChanges::none()->withIsFavorite(false));
        $this->events->dispatch(new CommunicationDraftUnfavorited($communicationId, $actor));
    }

    public function update(int $communicationId, string $title, string $body, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->status === CommunicationStatus::Discarded->value) {
            throw new CommunicationNotEditableException;
        }

        $this->communications->updateCommunication($communicationId, CommunicationChanges::none()
            ->withGeneratedTitle($title)
            ->withGeneratedBody($body));
        $this->events->dispatch(new CommunicationDraftEdited($communicationId, $actor));
    }

    public function save(int $communicationId, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->status !== CommunicationStatus::Draft->value) {
            throw new CommunicationNotDraftException;
        }

        $this->communications->updateCommunication($communicationId, CommunicationChanges::none()->withStatus(CommunicationStatus::Approved));
        $this->events->dispatch(new CommunicationDraftApproved($communicationId, $actor));
    }

    public function discard(int $communicationId, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->status === CommunicationStatus::Discarded->value) {
            throw new CommunicationAlreadyDiscardedException;
        }

        $this->communications->updateCommunication($communicationId, CommunicationChanges::none()->withStatus(CommunicationStatus::Discarded));
        $this->events->dispatch(new CommunicationDraftDiscarded($communicationId, $actor));
    }
}
