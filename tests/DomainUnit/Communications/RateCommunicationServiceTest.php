<?php

use App\Mvp\Communications\Application\UseCases\RateCommunicationService;
use App\Mvp\Communications\Domain\Events\CommunicationRated;
use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyRatedException;
use App\Mvp\Support\Identity\Actor;
use Tests\DomainUnit\Communications\Fakes\FakeClock;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;
use Tests\DomainUnit\Communications\Fakes\RecordingEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS). Prima bloccato da
 * now() (facade Date, richiede il container): l'orologio iniettato
 * (Psr\Clock\ClockInterface, adapter SystemClock) lo sblocca senza
 * introdurre una porta custom, stesso trattamento di Psr\Log\LoggerInterface.
 */
function fakeRateCommunicationActor(): Actor
{
    return new Actor('user-1', 'operator@example.test', 'Operator', 'tenant-1', ['mvp-operator']);
}

test('rate persists the rating with the injected clock and dispatches CommunicationRated', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['rating' => null]);
    $events = new RecordingEventDispatcher;
    $clock = new FakeClock(new DateTimeImmutable('2026-01-15 10:00:00'));

    (new RateCommunicationService($repository, $events, $clock))
        ->rate(1, 5, 'Ottima bozza.', fakeRateCommunicationActor());

    expect($repository->findCommunication(1)->rating)->toBe(5)
        ->and($events->hasDispatched(CommunicationRated::class))->toBeTrue();
});

test('rate refuses a communication already rated', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['rating' => 3]);
    $events = new RecordingEventDispatcher;
    $clock = new FakeClock(new DateTimeImmutable);

    expect(fn () => (new RateCommunicationService($repository, $events, $clock))->rate(1, 5, null, fakeRateCommunicationActor()))
        ->toThrow(CommunicationAlreadyRatedException::class)
        ->and($events->events())->toBeEmpty();
});

test('rate normalizes a blank comment to no comment', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['rating' => null]);
    $events = new RecordingEventDispatcher;
    $clock = new FakeClock(new DateTimeImmutable);

    (new RateCommunicationService($repository, $events, $clock))->rate(1, 4, '   ', fakeRateCommunicationActor());

    /** @var CommunicationRated $dispatched */
    $dispatched = $events->events()[0];

    expect($dispatched->hasComment)->toBeFalse();
});
