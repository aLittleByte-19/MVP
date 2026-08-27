<?php

use App\Mvp\Communications\Application\UseCases\CommunicationDraftService;
use App\Mvp\Communications\Domain\Events\CommunicationDraftApproved;
use App\Mvp\Communications\Domain\Events\CommunicationDraftDiscarded;
use App\Mvp\Communications\Domain\Events\CommunicationDraftFavorited;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyDiscardedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyFavoritedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotAuthorizedException;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotDraftException;
use App\Mvp\Support\Identity\Actor;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;
use Tests\DomainUnit\Communications\Fakes\PassthroughTransactionManager;
use Tests\DomainUnit\Communications\Fakes\RecordingEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS, vedi refactory.md
 * Compito 3 punto 2): Actor e' un value object di dominio, costruibile
 * senza autenticazione/middleware reali.
 */
function fakeActor(): Actor
{
    return new Actor(
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

    (new CommunicationDraftService($repository, $events, new PassthroughTransactionManager))->favorite(1, fakeActor());

    expect($repository->findCommunication(1)->isFavorite())->toBeTrue()
        ->and($events->hasDispatched(CommunicationDraftFavorited::class))->toBeTrue()
        // Il fake distingue una lettura con lock da una senza (vedi il suo
        // docblock): senza questo assert, un regresso che tornasse a
        // findCommunication() (perdendo il lock pessimistico) non farebbe
        // fallire nessun test.
        ->and($repository->forUpdateReadCount(1))->toBe(1);
});

test('favorite refuses an already favorite draft', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['is_favorite' => true]);
    $events = new RecordingEventDispatcher;

    expect(fn () => (new CommunicationDraftService($repository, $events, new PassthroughTransactionManager))->favorite(1, fakeActor()))
        ->toThrow(CommunicationAlreadyFavoritedException::class)
        ->and($events->events())->toBeEmpty();
});

test('save approves a draft and dispatches CommunicationDraftApproved', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'draft']);
    $events = new RecordingEventDispatcher;

    (new CommunicationDraftService($repository, $events, new PassthroughTransactionManager))->save(1, fakeActor());

    expect($repository->findCommunication(1)->status()->value)->toBe('approved')
        ->and($events->hasDispatched(CommunicationDraftApproved::class))->toBeTrue()
        ->and($repository->forUpdateReadCount(1))->toBe(1);
});

test('save refuses a draft that is not in draft status', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'approved']);
    $events = new RecordingEventDispatcher;

    expect(fn () => (new CommunicationDraftService($repository, $events, new PassthroughTransactionManager))->save(1, fakeActor()))
        ->toThrow(CommunicationNotDraftException::class);
});

test('discard marks the draft discarded and dispatches CommunicationDraftDiscarded', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'draft']);
    $events = new RecordingEventDispatcher;

    (new CommunicationDraftService($repository, $events, new PassthroughTransactionManager))->discard(1, fakeActor());

    expect($repository->findCommunication(1)->status()->value)->toBe('discarded')
        ->and($events->hasDispatched(CommunicationDraftDiscarded::class))->toBeTrue()
        ->and($repository->forUpdateReadCount(1))->toBe(1);
});

test('discard refuses an already discarded draft', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'discarded']);
    $events = new RecordingEventDispatcher;

    expect(fn () => (new CommunicationDraftService($repository, $events, new PassthroughTransactionManager))->discard(1, fakeActor()))
        ->toThrow(CommunicationAlreadyDiscardedException::class);
});

function fakeIntruderActor(): Actor
{
    return new Actor('user-2', 'other@example.test', 'Other', 'altro-tenant', ['mvp-operator']);
}

/**
 * Difesa in profondita' (vedi il docblock della classe): il controllo HTTP
 * (AuthorizesCommunications) protegge solo chi lo chiama, questi test
 * verificano che il caso d'uso applicativo resti sicuro anche da solo.
 */
test('save refuses a draft outside the actor tenant scope', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'draft']);
    $events = new RecordingEventDispatcher;

    expect(fn () => (new CommunicationDraftService($repository, $events, new PassthroughTransactionManager))->save(1, fakeIntruderActor()))
        ->toThrow(CommunicationNotAuthorizedException::class)
        ->and($events->events())->toBeEmpty();
});

test('favorite refuses a draft outside the actor tenant scope', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['is_favorite' => false]);
    $events = new RecordingEventDispatcher;

    expect(fn () => (new CommunicationDraftService($repository, $events, new PassthroughTransactionManager))->favorite(1, fakeIntruderActor()))
        ->toThrow(CommunicationNotAuthorizedException::class)
        ->and($events->events())->toBeEmpty();
});

test('unfavorite refuses a draft outside the actor tenant scope', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['is_favorite' => true]);
    $events = new RecordingEventDispatcher;

    expect(fn () => (new CommunicationDraftService($repository, $events, new PassthroughTransactionManager))->unfavorite(1, fakeIntruderActor()))
        ->toThrow(CommunicationNotAuthorizedException::class)
        ->and($events->events())->toBeEmpty()
        ->and($repository->forUpdateReadCount(1))->toBe(1);
});

test('update refuses a draft outside the actor tenant scope', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'draft']);
    $events = new RecordingEventDispatcher;

    expect(fn () => (new CommunicationDraftService($repository, $events, new PassthroughTransactionManager))->update(1, 'Titolo', 'Corpo', fakeIntruderActor()))
        ->toThrow(CommunicationNotAuthorizedException::class)
        ->and($events->events())->toBeEmpty()
        ->and($repository->forUpdateReadCount(1))->toBe(1);
});

test('discard refuses a draft outside the actor tenant scope', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['status' => 'draft']);
    $events = new RecordingEventDispatcher;

    expect(fn () => (new CommunicationDraftService($repository, $events, new PassthroughTransactionManager))->discard(1, fakeIntruderActor()))
        ->toThrow(CommunicationNotAuthorizedException::class)
        ->and($events->events())->toBeEmpty();
});
