<?php

use App\Mvp\Communications\Application\UseCases\PollCommunicationProgressService;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB).
 */
test('poll reports generation and cover status alongside the generated content', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, [
        'generation_status' => 'processing',
        'cover_status' => 'processing',
        'generated_title' => 'Titolo generato',
        'generated_body' => 'Corpo generato',
    ]);

    $snapshot = (new PollCommunicationProgressService($repository))->poll(1);

    expect($snapshot->generationStatus)->toBe('processing')
        ->and($snapshot->coverStatus)->toBe('processing')
        ->and($snapshot->generatedTitle)->toBe('Titolo generato')
        ->and($snapshot->generatedBody)->toBe('Corpo generato');
});

test('poll surfaces the failure messages when generation or cover failed', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, [
        'generation_status' => 'failed',
        'error_message' => 'Generazione non disponibile.',
        'cover_status' => 'failed',
        'cover_error' => 'Copertina non disponibile.',
    ]);

    $snapshot = (new PollCommunicationProgressService($repository))->poll(1);

    expect($snapshot->generationStatus)->toBe('failed')
        ->and($snapshot->errorMessage)->toBe('Generazione non disponibile.')
        ->and($snapshot->coverStatus)->toBe('failed')
        ->and($snapshot->coverError)->toBe('Copertina non disponibile.');
});
