<?php

use App\Mvp\Communications\Application\UseCases\DeleteCommunicationService;
use App\Mvp\Communications\Domain\Events\CommunicationDeleted;
use App\Mvp\Identity\MvpUser;
use Tests\DomainUnit\Communications\Fakes\FakeCommunicationCoverStorage;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;
use Tests\DomainUnit\Communications\Fakes\RecordingEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS). Funzione locale
 * (non condivisa fra file): con `--parallel` ogni worker Paratest carica solo
 * un sottoinsieme dei file di test, quindi una funzione globale dichiarata
 * altrove puo' non essere disponibile nello stesso processo.
 */
function fakeDeleteCommunicationActor(): MvpUser
{
    return new MvpUser('user-1', 'operator@example.test', 'Operator', 'tenant-1', ['mvp-operator']);
}

test('delete removes the communication and its cover, then dispatches CommunicationDeleted', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['cover_image_path' => 'communications/covers/1/x.png']);
    $storage = new FakeCommunicationCoverStorage;
    $storage->store('communications/covers/1/x.png', 'bytes');
    $events = new RecordingEventDispatcher;

    (new DeleteCommunicationService($repository, $storage, $events))->delete(1, fakeDeleteCommunicationActor());

    expect($storage->deletedPaths())->toContain('communications/covers/1/x.png')
        ->and($events->hasDispatched(CommunicationDeleted::class))->toBeTrue()
        ->and(fn () => $repository->findCommunication(1))->toThrow(RuntimeException::class);
});

test('delete does not touch storage when there is no cover', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['cover_image_path' => null]);
    $storage = new FakeCommunicationCoverStorage;
    $events = new RecordingEventDispatcher;

    (new DeleteCommunicationService($repository, $storage, $events))->delete(1, fakeDeleteCommunicationActor());

    expect($storage->deletedPaths())->toBeEmpty()
        ->and($events->hasDispatched(CommunicationDeleted::class))->toBeTrue();
});
