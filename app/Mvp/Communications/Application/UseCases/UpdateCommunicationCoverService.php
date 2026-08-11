<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Events\CommunicationCoverRemoved;
use App\Mvp\Communications\Domain\Events\CommunicationCoverReplaced;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Communications\Domain\Ports\Inbound\UpdateCommunicationCoverUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationEventDispatcherPort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Enums\CommunicationStatus;
use App\Mvp\Communications\Enums\CoverImageSource;
use App\Mvp\Communications\Enums\CoverImageStatus;
use App\Mvp\Identity\MvpUser;
use App\Mvp\Support\Identifiers\UniqueIdGeneratorPort;

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

    public function update(int $communicationId, string $bytes, string $mime, int $size, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->status === CommunicationStatus::Discarded->value) {
            throw new CommunicationNotEditableException;
        }

        $path = $this->newPath($communicationId, $mime);
        $this->storage->store($path, $bytes);

        $this->communications->updateCommunication($communicationId, [
            'cover_image_path' => $path,
            'cover_image_mime' => $mime,
            'cover_image_size' => $size,
            'cover_image_source' => CoverImageSource::Manual,
            'cover_status' => CoverImageStatus::Ready,
            'cover_error' => null,
        ]);

        if ($communication->coverImagePath !== null) {
            $this->storage->delete($communication->coverImagePath);
        }

        $this->events->dispatch(new CommunicationCoverReplaced($communicationId, $communication->tenantId, $actor, $mime, $size));
    }

    public function remove(int $communicationId, MvpUser $actor): void
    {
        $communication = $this->communications->findCommunication($communicationId);

        if ($communication->status === CommunicationStatus::Discarded->value) {
            throw new CommunicationNotEditableException;
        }

        $this->communications->updateCommunication($communicationId, [
            'cover_image_path' => null,
            'cover_image_mime' => null,
            'cover_image_size' => null,
            'cover_image_source' => null,
            'cover_status' => CoverImageStatus::Removed,
            'cover_error' => null,
        ]);

        if ($communication->coverImagePath !== null) {
            $this->storage->delete($communication->coverImagePath);
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
