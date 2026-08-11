<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Exceptions\CommunicationCoverUnavailableException;
use App\Mvp\Communications\Domain\Ports\Inbound\DownloadCommunicationCoverUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationCoverStoragePort;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\ValueObjects\DownloadableCoverImage;

class DownloadCommunicationCoverService implements DownloadCommunicationCoverUseCase
{
    public function __construct(
        private readonly CommunicationRepository $communications,
        private readonly CommunicationCoverStoragePort $storage,
    ) {}

    public function download(int $communicationId): DownloadableCoverImage
    {
        $communication = $this->communications->findCommunication($communicationId);
        $path = $communication->coverImagePath;

        if ($path === null || $path === '' || ! $this->storage->exists($path)) {
            throw new CommunicationCoverUnavailableException;
        }

        return new DownloadableCoverImage($this->storage->read($path), $communication->coverImageMime ?: 'image/png');
    }
}
