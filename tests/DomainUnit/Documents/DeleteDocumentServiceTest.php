<?php

use App\Mvp\Documents\Application\UseCases\DeleteDocumentService;
use App\Mvp\Documents\Domain\Events\SubDocumentDeleted;
use App\Mvp\Support\Identity\Actor;
use Psr\Log\NullLogger;
use Tests\DomainUnit\Documents\Fakes\FakeDocumentStorage;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;
use Tests\DomainUnit\Documents\Fakes\RecordingDocumentEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS).
 */
function fakeDocumentActor(): Actor
{
    return new Actor('user-1', 'operator@example.test', 'Operator', 'tenant-1', ['mvp-operator']);
}

test('delete removes the sub-document, its file, and dispatches SubDocumentDeleted', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1, ['file_path' => 'documents/sub/10.pdf']);
    $storage = new FakeDocumentStorage;
    $events = new RecordingDocumentEventDispatcher;

    (new DeleteDocumentService($documents, $storage, $events, new NullLogger))->delete(10, fakeDocumentActor());

    expect($storage->deletedPaths())->toContain('documents/sub/10.pdf')
        ->and($events->hasDispatched(SubDocumentDeleted::class))->toBeTrue();
});

test('delete also removes the original document once its last sub-document is gone', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['file_path' => 'documents/originals/1.pdf']);
    $documents->seedSubDocument(10, 1);
    $storage = new FakeDocumentStorage;
    $events = new RecordingDocumentEventDispatcher;

    (new DeleteDocumentService($documents, $storage, $events, new NullLogger))->delete(10, fakeDocumentActor());

    expect(fn () => $documents->findOriginalDocument(1))->toThrow(RuntimeException::class)
        ->and($storage->deletedPaths())->toContain('documents/originals/1.pdf');
});

test('delete keeps the original document when other sub-documents remain', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1);
    $documents->seedSubDocument(11, 1);
    $storage = new FakeDocumentStorage;
    $events = new RecordingDocumentEventDispatcher;

    (new DeleteDocumentService($documents, $storage, $events, new NullLogger))->delete(10, fakeDocumentActor());

    expect($documents->findOriginalDocument(1))->not->toBeNull();
});

test('delete succeeds and still dispatches SubDocumentDeleted when storage cleanup fails', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(10, 1, ['file_path' => 'documents/sub/10.pdf']);
    $storage = new FakeDocumentStorage;
    $storage->willThrowOnDelete(new RuntimeException('S3 non raggiungibile'));
    $events = new RecordingDocumentEventDispatcher;

    (new DeleteDocumentService($documents, $storage, $events, new NullLogger))->delete(10, fakeDocumentActor());

    expect(fn () => $documents->findSubDocument(10))->toThrow(RuntimeException::class)
        ->and($events->hasDispatched(SubDocumentDeleted::class))->toBeTrue();
});
