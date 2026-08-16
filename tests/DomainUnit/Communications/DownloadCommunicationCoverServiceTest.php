<?php

use App\Mvp\Communications\Application\UseCases\DownloadCommunicationCoverService;
use App\Mvp\Communications\Domain\Exceptions\CommunicationCoverUnavailableException;
use Tests\DomainUnit\Communications\Fakes\FakeCommunicationCoverStorage;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB). Prima il controller
 * chiamava Storage::disk() direttamente, bypassando CommunicationCoverStoragePort:
 * ora l'I/O passa da DownloadCommunicationCoverUseCase, testabile in isolamento.
 */
test('download returns the stored bytes and mime of the cover', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['cover_image_path' => 'communications/covers/1.png', 'cover_image_mime' => 'image/webp']);
    $storage = new FakeCommunicationCoverStorage;
    $storage->store('communications/covers/1.png', 'contenuto-copertina');

    $result = (new DownloadCommunicationCoverService($repository, $storage))->download(1);

    expect($result->bytes)->toBe('contenuto-copertina')
        ->and($result->mime)->toBe('image/webp');
});

test('download refuses when the communication has no cover path', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['cover_image_path' => null]);
    $storage = new FakeCommunicationCoverStorage;

    expect(fn () => (new DownloadCommunicationCoverService($repository, $storage))->download(1))
        ->toThrow(CommunicationCoverUnavailableException::class);
});

test('download refuses when the cover path is set but the file is missing from storage', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['cover_image_path' => 'communications/covers/1.png']);
    $storage = new FakeCommunicationCoverStorage;

    expect(fn () => (new DownloadCommunicationCoverService($repository, $storage))->download(1))
        ->toThrow(CommunicationCoverUnavailableException::class);
});
