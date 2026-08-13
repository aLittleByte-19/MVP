<?php

use App\Exceptions\InvalidAiOutputException;
use App\Mvp\Communications\Application\UseCases\GenerateCommunicationTextService;
use App\Mvp\Communications\Domain\Events\AiOutputRejected;
use App\Mvp\Communications\Domain\Events\CommunicationGenerationFailed;
use App\Mvp\Communications\Domain\Events\CommunicationTextGenerated;
use App\Mvp\Communications\Domain\ValueObjects\GeneratedCommunicationText;
use Tests\DomainUnit\Communications\Fakes\FakeCommunicationAiGateway;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;
use Tests\DomainUnit\Communications\Fakes\RecordingEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS, vedi refactory.md
 * Compito 3 punto 2 e ADR 0010): il caso d'uso e' istanziato direttamente,
 * con adapter di test che implementano le porte secondarie in memoria.
 */
test('generate persists the AI output and dispatches CommunicationTextGenerated', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['tone' => 'Chiaro e diretto', 'style' => 'Testo informativo']);
    $ai = new FakeCommunicationAiGateway;
    $ai->willReturnText(new GeneratedCommunicationText(title: 'Titolo AI', body: 'Corpo AI', imagePrompt: 'Direzione visiva'));
    $events = new RecordingEventDispatcher;

    $service = new GenerateCommunicationTextService($repository, $ai, $events);
    $result = $service->generate(1);

    expect($result)->toBe(['skipped' => false, 'title' => 'Titolo AI'])
        ->and($repository->findCommunication(1)->generatedBody())->toBe('Corpo AI')
        ->and($repository->findCommunication(1)->imagePrompt())->toBe('Direzione visiva')
        ->and($events->hasDispatched(CommunicationTextGenerated::class))->toBeTrue();
});

test('generate skips when the text was already generated', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['generated_body' => 'Gia presente']);
    $events = new RecordingEventDispatcher;

    $service = new GenerateCommunicationTextService($repository, new FakeCommunicationAiGateway, $events);
    $result = $service->generate(1);

    expect($result)->toBe(['skipped' => true, 'title' => null])
        ->and($events->events())->toBeEmpty();
});

test('generate dispatches AiOutputRejected and rethrows on invalid AI output', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1);
    $ai = new FakeCommunicationAiGateway;
    $ai->willThrowOnText(new InvalidAiOutputException('generate_text', ['title is required']));
    $events = new RecordingEventDispatcher;

    $service = new GenerateCommunicationTextService($repository, $ai, $events);

    expect(fn () => $service->generate(1))->toThrow(InvalidAiOutputException::class)
        ->and($events->hasDispatched(AiOutputRejected::class))->toBeTrue()
        ->and($repository->findCommunication(1)->generatedBody())->toBeNull();
});

test('generate dispatches CommunicationGenerationFailed and rethrows on any other failure', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1);
    $ai = new FakeCommunicationAiGateway;
    $ai->willThrowOnText(new RuntimeException('Bedrock non raggiungibile'));
    $events = new RecordingEventDispatcher;

    $service = new GenerateCommunicationTextService($repository, $ai, $events);

    expect(fn () => $service->generate(1))->toThrow(RuntimeException::class, 'Bedrock non raggiungibile')
        ->and($events->hasDispatched(CommunicationGenerationFailed::class))->toBeTrue()
        ->and($events->hasDispatched(AiOutputRejected::class))->toBeFalse()
        ->and($repository->findCommunication(1)->errorMessage())->toBe('Generazione del testo non disponibile. Riprova più tardi.');
});
