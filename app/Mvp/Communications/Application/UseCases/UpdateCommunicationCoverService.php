<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Events\CommunicationCoverRemoved;
use App\Mvp\Communications\Domain\Events\CommunicationCoverReplaced;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Communications\Domain\Ports\Inbound\UpdateCommunicationCoverUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Support\Identifiers\UniqueIdGeneratorPort;
use App\Mvp\Support\Identity\Actor;

class UpdateCommunicationCoverService implements UpdateCommunicationCoverUseCase
{
    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationCoverStoragePort $storage,
        private readonly CommunicationEventDispatcherPort $events,
        private readonly UniqueIdGeneratorPort $ids,
        private readonly string $coverPathPrefix = 'communications/covers',
    ) {}

    public function update(int $communicationId, string $bytes, string $mime, int $size, Actor $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);
        $oldCoverPath = $communication->coverImagePath();

        if (! $communication->isEditable()) {
            throw new CommunicationNotEditableException;
        }

        $path = $this->newPath($communicationId, $mime);
        $this->storage->store($path, $bytes);

        $communication->replaceCover($path, $mime, $size);
        $this->communications->saveCommunication($communication);

        if ($oldCoverPath !== null) {
            $this->storage->delete($oldCoverPath);
        }

        $this->events->dispatch(new CommunicationCoverReplaced($communicationId, $communication->tenantId, $actor, $mime, $size));
    }

    public function remove(int $communicationId, Actor $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);
        $oldCoverPath = $communication->coverImagePath();

        $communication->removeCover();
        $this->communications->saveCommunication($communication);

        if ($oldCoverPath !== null) {
            $this->storage->delete($oldCoverPath);
        }

        $this->events->dispatch(new CommunicationCoverRemoved($communicationId, $communication->tenantId, $actor));
    }

    private function newPath(int $communicationId, string $mime): string
    {
        return sprintf(
            '%s/%d/%s.%s',
            trim($this->coverPathPrefix, '/'),
            $communicationId,
            $this->ids->generate(),
            self::EXTENSIONS[strtolower($mime)] ?? 'png',
        );
    }
}
