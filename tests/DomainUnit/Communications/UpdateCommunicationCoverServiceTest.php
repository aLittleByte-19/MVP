<?php

use App\Mvp\Communications\Application\UseCases\UpdateCommunicationCoverService;
use App\Mvp\Communications\Domain\Events\CommunicationCoverRemoved;
use App\Mvp\Communications\Domain\Events\CommunicationCoverReplaced;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Support\Identity\Actor;
use Tests\DomainUnit\Communications\Fakes\FakeCommunicationCoverStorage;
use Tests\DomainUnit\Communications\Fakes\FakeUniqueIdGenerator;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;
use Tests\DomainUnit\Communications\Fakes\RecordingEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB). Prima update()/remove()
 * non dispatchavano alcun evento: l'audit veniva scritto direttamente nel
 * controller HTTP, un'asimmetria rispetto a ogni altra azione del dominio
 * (vedi ADR 0010).
 */
function fakeUpdateCoverActor(): Actor
{
    return new Actor('user-1', 'operator@example.test', 'Operator', 'tenant-1', ['mvp-operator']);
}

test('update replaces the cover, cleans up the old file and dispatches CommunicationCoverReplaced', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['cover_image_path' => 'communications/covers/1/old.png']);
    $storage = new FakeCommunicationCoverStorage;
    $storage->store('communications/covers/1/old.png', 'vecchia-copertina');
    $events = new RecordingEventDispatcher;

    $service = new UpdateCommunicationCoverService($repository, $storage, $events, new FakeUniqueIdGenerator('new-id'), 'communications/covers');
    $service->update(1, 'nuova-copertina', 'image/webp', 1234, fakeUpdateCoverActor());

    $record = $repository->findCommunication(1);

    expect($record->coverImagePath)->toBe('communications/covers/1/new-id.webp')
        ->and($record->coverImageMime)->toBe('image/webp')
        ->and($record->coverStatus)->toBe('ready')
        ->and($storage->has('communications/covers/1/old.png'))->toBeFalse()
        ->and($events->hasDispatched(CommunicationCoverReplaced::class))->toBeTrue();
});

test('update refuses to replace the cover of a discarded communication', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'discarded']);
    $events = new RecordingEventDispatcher;

    $service = new UpdateCommunicationCoverService($repository, new FakeCommunicationCoverStorage, $events, new FakeUniqueIdGenerator, 'communications/covers');

    expect(fn () => $service->update(1, 'bytes', 'image/png', 10, fakeUpdateCoverActor()))
        ->toThrow(CommunicationNotEditableException::class)
        ->and($events->events())->toBeEmpty();
});

test('remove clears the cover, deletes the file and dispatches CommunicationCoverRemoved', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['cover_image_path' => 'communications/covers/1/old.png']);
    $storage = new FakeCommunicationCoverStorage;
    $storage->store('communications/covers/1/old.png', 'vecchia-copertina');
    $events = new RecordingEventDispatcher;

    $service = new UpdateCommunicationCoverService($repository, $storage, $events, new FakeUniqueIdGenerator, 'communications/covers');
    $service->remove(1, fakeUpdateCoverActor());

    $record = $repository->findCommunication(1);

    expect($record->coverImagePath)->toBeNull()
        ->and($record->coverStatus)->toBe('removed')
        ->and($storage->has('communications/covers/1/old.png'))->toBeFalse()
        ->and($events->hasDispatched(CommunicationCoverRemoved::class))->toBeTrue();
});

test('remove refuses to touch the cover of a discarded communication', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'discarded']);
    $events = new RecordingEventDispatcher;

    $service = new UpdateCommunicationCoverService($repository, new FakeCommunicationCoverStorage, $events, new FakeUniqueIdGenerator, 'communications/covers');

    expect(fn () => $service->remove(1, fakeUpdateCoverActor()))
        ->toThrow(CommunicationNotEditableException::class)
        ->and($events->events())->toBeEmpty();
});
