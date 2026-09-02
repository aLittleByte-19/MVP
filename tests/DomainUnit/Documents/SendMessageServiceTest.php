<?php

use App\Mvp\Documents\Application\UseCases\SendMessageService;
use App\Mvp\Documents\Domain\Events\SendMessageExported;
use App\Mvp\Documents\Domain\Events\SendMessageOverridesCorrected;
use App\Mvp\Documents\Domain\Exceptions\DocumentNotAuthorizedException;
use App\Mvp\Documents\Domain\Exceptions\SendMessageAttachmentUnavailableException;
use App\Mvp\Documents\Domain\Exceptions\SendMessageNotConfirmedException;
use App\Mvp\Support\Identity\Actor;
use Tests\DomainUnit\Documents\Fakes\FakeDocumentStorage;
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
    return new Actor('user-1', 'operator@example.test', 'Operator', 'tenant-test', ['mvp-operator']);
}

/** Lo storage con dentro il PDF del sotto-documento, quello che l'export accoda. */
function fakeSendMessageStorage(int $subDocumentId = 10): FakeDocumentStorage
{
    $storage = new FakeDocumentStorage;
    $storage->write("documents/sub/{$subDocumentId}.pdf", 'pdf-del-sotto-documento');

    return $storage;
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

    $rendered = (new SendMessageService($documents, new FakeSendMessageRenderer, fakeSendMessageStorage(), $events))
        ->preview(10, fakeSendMessageActor());

    expect($rendered->pdf)->toContain('Cedolino')
        ->and($events->events())->toBeEmpty();
});

test('export marks the message sent once, and dispatches SendMessageExported only the first time', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1, ['send_status' => 'pending', 'review_status' => 'manually_validated']);
    $events = new RecordingDocumentEventDispatcher;
    $service = new SendMessageService($documents, new FakeSendMessageRenderer, fakeSendMessageStorage(), $events);

    $service->export(10, fakeSendMessageActor());
    expect($events->hasDispatched(SendMessageExported::class))->toBeTrue();

    $eventsSecondCall = new RecordingDocumentEventDispatcher;
    (new SendMessageService($documents, new FakeSendMessageRenderer, fakeSendMessageStorage(), $eventsSecondCall))->export(10, fakeSendMessageActor());

    // Transizione a senso unico: il secondo export non re-invia l'evento.
    expect($eventsSecondCall->events())->toBeEmpty()
        ->and($documents->findSubDocument(10)->sendStatus()->value)->toBe('sent');
});

test('export refuses a sub-document that belongs to another tenant', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1, ['send_status' => 'pending', 'review_status' => 'manually_validated']);
    $events = new RecordingDocumentEventDispatcher;
    $intruder = new Actor('user-2', 'other@example.test', 'Other', 'altro-tenant', ['mvp-operator']);

    expect(fn () => (new SendMessageService($documents, new FakeSendMessageRenderer, fakeSendMessageStorage(), $events))->export(10, $intruder))
        ->toThrow(DocumentNotAuthorizedException::class)
        ->and($events->events())->toBeEmpty();
});

test('updateOverrides persists the provided fields and dispatches SendMessageOverridesCorrected', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1);
    $events = new RecordingDocumentEventDispatcher;

    (new SendMessageService($documents, new FakeSendMessageRenderer, fakeSendMessageStorage(), $events))
        ->updateOverrides(10, ['subject' => 'Oggetto corretto'], fakeSendMessageActor());

    expect($events->hasDispatched(SendMessageOverridesCorrected::class))->toBeTrue();
});

test('export accoda al messaggio il documento del destinatario', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1, ['document_type' => 'cedolino paga', 'send_status' => 'pending', 'review_status' => 'manually_validated']);
    $renderer = new FakeSendMessageRenderer;

    (new SendMessageService($documents, $renderer, fakeSendMessageStorage(), new RecordingDocumentEventDispatcher))
        ->export(10, fakeSendMessageActor());

    expect($renderer->lastAttachment)->toBe('pdf-del-sotto-documento');
});

test('export si ferma quando il documento da allegare non e\' sullo storage', function () {
    // Meglio un errore che un messaggio che dichiara un allegato assente. Lo
    // stato di scaricamento non deve muoversi: e' una transizione a senso unico.
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1, ['send_status' => 'pending', 'review_status' => 'manually_validated']);
    $events = new RecordingDocumentEventDispatcher;

    $export = fn () => (new SendMessageService($documents, new FakeSendMessageRenderer, new FakeDocumentStorage, $events))
        ->export(10, fakeSendMessageActor());

    expect($export)->toThrow(SendMessageAttachmentUnavailableException::class)
        ->and($documents->findSubDocument(10)->sendStatus()->value)->toBe('pending')
        ->and($events->events())->toBeEmpty();
});

test('l\'oggetto nomina il documento e il periodo dichiarato al caricamento', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['manual_reference_month' => 6, 'manual_reference_year' => 2026]);
    $documents->seedSubDocument(10, 1, ['document_type' => 'cedolino paga', 'send_status' => 'pending', 'review_status' => 'manually_validated']);
    $renderer = new FakeSendMessageRenderer;

    $rendered = (new SendMessageService($documents, $renderer, fakeSendMessageStorage(), new RecordingDocumentEventDispatcher))
        ->preview(10, fakeSendMessageActor());

    expect($rendered->pdf)->toContain('Cedolino paga di giugno 2026');
});

test('export si ferma finche\' una persona non ha confermato i dati', function () {
    // La validazione automatica non basta: il download consegna il documento a
    // una persona, e prima qualcuno deve aver guardato la scheda.
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1, ['send_status' => 'pending', 'review_status' => 'auto_validated']);
    $events = new RecordingDocumentEventDispatcher;

    $export = fn () => (new SendMessageService($documents, new FakeSendMessageRenderer, fakeSendMessageStorage(), $events))
        ->export(10, fakeSendMessageActor());

    expect($export)->toThrow(SendMessageNotConfirmedException::class)
        ->and($documents->findSubDocument(10)->sendStatus()->value)->toBe('pending')
        ->and($events->events())->toBeEmpty();
});

test('l\'anteprima resta consultabile prima della conferma', function () {
    // Guardare il messaggio e' proprio il modo di decidere se confermare.
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1, ['send_status' => 'pending', 'review_status' => 'needs_review']);

    $rendered = (new SendMessageService($documents, new FakeSendMessageRenderer, fakeSendMessageStorage(), new RecordingDocumentEventDispatcher))
        ->preview(10, fakeSendMessageActor());

    expect($rendered->pdf)->not->toBeEmpty();
});
