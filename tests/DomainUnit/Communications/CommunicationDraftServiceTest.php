<?php

use App\Mvp\Communications\Application\UseCases\CommunicationDraftService;
use App\Mvp\Communications\Domain\Events\CommunicationDraftApproved;
use App\Mvp\Communications\Domain\Events\CommunicationDraftDiscarded;
use App\Mvp\Communications\Domain\Events\CommunicationDraftFavorited;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyDiscardedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyFavoritedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotDraftException;
use App\Mvp\Identity\MvpUser;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;
use Tests\DomainUnit\Communications\Fakes\RecordingEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS, vedi refactory.md
 * Compito 3 punto 2): MvpUser e' un value object di dominio, costruibile
 * senza autenticazione/middleware reali.
 */
function fakeActor(): MvpUser
{
    return new MvpUser(
        'user-1',
        'operator@example.test',
        'Operator',
        'tenant-1',
        ['mvp-operator'],
    );
}

test('favorite marks the draft and dispatches CommunicationDraftFavorited', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['is_favorite' => false]);
    $events = new RecordingEventDispatcher;

    (new CommunicationDraftService($repository, $events))->favorite(1, fakeActor());

    expect($repository->findCommunication(1)->isFavorite)->toBeTrue()
        ->and($events->hasDispatched(CommunicationDraftFavorited::class))->toBeTrue();
});

test('favorite refuses an already favorite draft', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['is_favorite' => true]);
    $events = new RecordingEventDispatcher;

    expect(fn () => (new CommunicationDraftService($repository, $events))->favorite(1, fakeActor()))
        ->toThrow(CommunicationAlreadyFavoritedException::class)
        ->and($events->events())->toBeEmpty();
});

test('save approves a draft and dispatches CommunicationDraftApproved', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'draft']);
    $events = new RecordingEventDispatcher;

    (new CommunicationDraftService($repository, $events))->save(1, fakeActor());

    expect($repository->findCommunication(1)->status)->toBe('approved')
        ->and($events->hasDispatched(CommunicationDraftApproved::class))->toBeTrue();
});

test('save refuses a draft that is not in draft status', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'approved']);
    $events = new RecordingEventDispatcher;

    expect(fn () => (new CommunicationDraftService($repository, $events))->save(1, fakeActor()))
        ->toThrow(CommunicationNotDraftException::class);
});

test('discard marks the draft discarded and dispatches CommunicationDraftDiscarded', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'draft']);
    $events = new RecordingEventDispatcher;

    (new CommunicationDraftService($repository, $events))->discard(1, fakeActor());

    expect($repository->findCommunication(1)->status)->toBe('discarded')
        ->and($events->hasDispatched(CommunicationDraftDiscarded::class))->toBeTrue();
});

test('discard refuses an already discarded draft', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'discarded']);
    $events = new RecordingEventDispatcher;

    expect(fn () => (new CommunicationDraftService($repository, $events))->discard(1, fakeActor()))
        ->toThrow(CommunicationAlreadyDiscardedException::class);
});
