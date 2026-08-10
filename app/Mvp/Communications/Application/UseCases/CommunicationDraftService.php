<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Audit\Services\AuditLogger;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyDiscardedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyFavoritedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotDraftException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotFavoritedException;
use App\Mvp\Communications\Domain\Ports\Inbound\CommunicationDraftUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Enums\CommunicationStatus;
use App\Mvp\Identity\MvpUser;

class CommunicationDraftService implements CommunicationDraftUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly AuditLogger $audit,
    ) {}

    public function favorite(int $communicationId, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->isFavorite) {
            throw new CommunicationAlreadyFavoritedException;
        }

        $this->communications->updateCommunication($communicationId, ['is_favorite' => true]);
        $this->audit->record('mvp-communication-favorited', $actor, 'communication', (string) $communicationId);
    }

    public function unfavorite(int $communicationId, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if (! $communication->isFavorite) {
            throw new CommunicationNotFavoritedException;
        }

        $this->communications->updateCommunication($communicationId, ['is_favorite' => false]);
        $this->audit->record('mvp-communication-unfavorited', $actor, 'communication', (string) $communicationId);
    }

    public function update(int $communicationId, string $title, string $body, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->status === CommunicationStatus::Discarded->value) {
            throw new CommunicationNotEditableException;
        }

        $this->communications->updateCommunication($communicationId, [
            'generated_title' => $title,
            'generated_body' => $body,
        ]);
        $this->audit->record('mvp-communication-edited', $actor, 'communication', (string) $communicationId, ['fields' => ['title', 'body']]);
    }

    public function save(int $communicationId, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->status !== CommunicationStatus::Draft->value) {
            throw new CommunicationNotDraftException;
        }

        $this->communications->updateCommunication($communicationId, ['status' => CommunicationStatus::Approved]);
        $this->audit->record('mvp-communication-saved', $actor, 'communication', (string) $communicationId);
    }

    public function discard(int $communicationId, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->status === CommunicationStatus::Discarded->value) {
            throw new CommunicationAlreadyDiscardedException;
        }

        $this->communications->updateCommunication($communicationId, ['status' => CommunicationStatus::Discarded]);
        $this->audit->record('mvp-communication-discarded', $actor, 'communication', (string) $communicationId);
    }
}
