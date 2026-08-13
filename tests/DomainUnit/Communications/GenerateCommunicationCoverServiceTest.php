<?php

use App\Mvp\Communications\Application\UseCases\GenerateCommunicationCoverService;
use App\Mvp\Communications\Domain\Events\CommunicationCoverDegraded;
use App\Mvp\Communications\Domain\Events\CommunicationCoverGenerated;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationImage;
use Psr\Log\NullLogger;
use Tests\DomainUnit\Communications\Fakes\FakeCommunicationAiGateway;
use Tests\DomainUnit\Communications\Fakes\FakeCommunicationCoverStorage;
use Tests\DomainUnit\Communications\Fakes\FakeUniqueIdGenerator;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;
use Tests\DomainUnit\Communications\Fakes\RecordingEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS, vedi refactory.md
 * Compito 3 punto 2). Il prefisso di storage e' passato al costruttore
 * invece che letto da config() dentro la classe, proprio per restare
 * istanziabile qui senza container.
 */
test('generate stores the image and dispatches CommunicationCoverGenerated', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['generated_body' => 'Corpo gia generato']);
    $ai = new FakeCommunicationAiGateway;
    $ai->willReturnImage(new GeneratedCommunicationImage(bytes: 'bytes-immagine', mime: 'image/png', warning: null, reason: null));
    $storage = new FakeCommunicationCoverStorage;
    $events = new RecordingEventDispatcher;

    $service = new GenerateCommunicationCoverService($repository, $storage, $ai, $events, new NullLogger, new FakeUniqueIdGenerator, 'communications/covers');
    $result = $service->generate(1);

    $record = $repository->findCommunication(1);

    expect($result)->toBe(['skipped' => false, 'coverStatus' => 'ready'])
        ->and($record->coverStatus()->value)->toBe('ready')
        ->and($record->coverImagePath())->toStartWith('communications/covers/1/')
        ->and($storage->has($record->coverImagePath()))->toBeTrue()
        ->and($events->hasDispatched(CommunicationCoverGenerated::class))->toBeTrue();
});

test('generate skips when the cover is already ready', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['cover_status' => 'ready']);
    $events = new RecordingEventDispatcher;

    $service = new GenerateCommunicationCoverService($repository, new FakeCommunicationCoverStorage, new FakeCommunicationAiGateway, $events, new NullLogger, new FakeUniqueIdGenerator, 'communications/covers');
    $result = $service->generate(1);

    expect($result)->toBe(['skipped' => true, 'coverStatus' => 'ready'])
        ->and($events->events())->toBeEmpty();
});

test('generate degrades and dispatches CommunicationCoverDegraded when the model returns no bytes', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['generated_body' => 'Corpo gia generato']);
    $ai = new FakeCommunicationAiGateway;
    $ai->willReturnImage(new GeneratedCommunicationImage(bytes: null, mime: 'image/png', warning: 'Modello non disponibile.', reason: 'model_not_configured'));
    $events = new RecordingEventDispatcher;

    $service = new GenerateCommunicationCoverService($repository, new FakeCommunicationCoverStorage, $ai, $events, new NullLogger, new FakeUniqueIdGenerator, 'communications/covers');
    $result = $service->generate(1);

    expect($result)->toBe(['skipped' => false, 'coverStatus' => 'failed'])
        ->and($repository->findCommunication(1)->coverStatus()->value)->toBe('failed')
        ->and($events->hasDispatched(CommunicationCoverDegraded::class))->toBeTrue();
});

test('generate degrades when storage fails, without persisting the image', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['generated_body' => 'Corpo gia generato']);
    $ai = new FakeCommunicationAiGateway;
    $ai->willReturnImage(new GeneratedCommunicationImage(bytes: 'bytes-immagine', mime: 'image/png', warning: null, reason: null));
    $storage = new FakeCommunicationCoverStorage;
    $storage->willThrowOnStore(new RuntimeException('Storage non raggiungibile.'));
    $events = new RecordingEventDispatcher;

    $service = new GenerateCommunicationCoverService($repository, $storage, $ai, $events, new NullLogger, new FakeUniqueIdGenerator, 'communications/covers');
    $result = $service->generate(1);

    expect($result)->toBe(['skipped' => false, 'coverStatus' => 'failed'])
        ->and($repository->findCommunication(1)->coverImagePath())->toBeNull()
        ->and($events->hasDispatched(CommunicationCoverDegraded::class))->toBeTrue();
});
