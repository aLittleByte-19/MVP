<?php

use App\Mvp\Documents\Application\UseCases\SendMessageService;
use App\Mvp\Documents\Domain\Events\SendMessageExported;
use App\Mvp\Documents\Domain\Events\SendMessageOverridesCorrected;
use App\Mvp\Support\Identity\Actor;
use Tests\DomainUnit\Documents\Fakes\FakeSendMessageRenderer;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;
use Tests\DomainUnit\Documents\Fakes\RecordingDocumentEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS). Str::slug() e'
 * usato internamente ma e' un'utility Illuminate pura (non una facade),
 * funziona senza container ne bootstrap.
 */
function fakeSendMessageActor(): Actor
{
    return new Actor('user-1', 'operator@example.test', 'Operator', 'tenant-1', ['mvp-operator']);
}

test('preview composes the message from extracted data without changing send status', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1, [
        'employee_first_name' => 'Mario',
        'employee_last_name' => 'Rossi',
        'document_type' => 'cedolino',
        'send_status' => 'pending',
    ]);
    $events = new RecordingDocumentEventDispatcher;

    $rendered = (new SendMessageService($documents, new FakeSendMessageRenderer, $events))
        ->preview(10, fakeSendMessageActor());

    expect($rendered->pdf)->toContain('Invio documento')
        ->and($events->events())->toBeEmpty();
});

test('export marks the message sent once, and dispatches SendMessageExported only the first time', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1, ['send_status' => 'pending']);
    $events = new RecordingDocumentEventDispatcher;
    $service = new SendMessageService($documents, new FakeSendMessageRenderer, $events);

    $service->export(10, fakeSendMessageActor());
    expect($events->hasDispatched(SendMessageExported::class))->toBeTrue();

    $eventsSecondCall = new RecordingDocumentEventDispatcher;
    (new SendMessageService($documents, new FakeSendMessageRenderer, $eventsSecondCall))->export(10, fakeSendMessageActor());

    // Transizione a senso unico: il secondo export non re-invia l'evento.
    expect($eventsSecondCall->events())->toBeEmpty();
});

test('updateOverrides persists the provided fields and dispatches SendMessageOverridesCorrected', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1);
    $events = new RecordingDocumentEventDispatcher;

    (new SendMessageService($documents, new FakeSendMessageRenderer, $events))
        ->updateOverrides(10, ['subject' => 'Oggetto corretto'], fakeSendMessageActor());

    expect($events->hasDispatched(SendMessageOverridesCorrected::class))->toBeTrue();
});
