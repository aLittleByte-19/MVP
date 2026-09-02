<?php

use App\Mvp\Documents\Application\UseCases\PreviewDocumentService;
use App\Mvp\Documents\Domain\Exceptions\DocumentNotAuthorizedException;
use App\Mvp\Documents\Domain\Exceptions\DocumentPreviewUnavailableException;
use App\Mvp\Support\Identity\Actor;
use Tests\DomainUnit\Documents\Fakes\FakeDocumentStorage;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB). Prima il controller
 * chiamava Storage::disk() direttamente, bypassando DocumentStoragePort:
 * ora l'I/O passa da PreviewDocumentUseCase, testabile in isolamento.
 */
function fakePreviewActor(string $tenantId = 'tenant-test'): Actor
{
    return new Actor('user-1', 'operator@example.test', 'Operator', $tenantId, ['mvp-operator']);
}

test('preview returns the stored bytes and the original filename', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(1, 1, ['original_filename' => 'busta-paga.pdf']);
    $storage = new FakeDocumentStorage;
    $storage->write('documents/sub/1.pdf', 'contenuto-documento');

    $result = (new PreviewDocumentService($documents, $storage))->preview(1, fakePreviewActor());

    expect($result->bytes)->toBe('contenuto-documento')
        ->and($result->filename)->toBe('busta-paga.pdf');
});

test('preview refuses when the file is missing from storage', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(1, 1);
    $storage = new FakeDocumentStorage;

    expect(fn () => (new PreviewDocumentService($documents, $storage))->preview(1, fakePreviewActor()))
        ->toThrow(DocumentPreviewUnavailableException::class);
});

test('preview refuses a sub-document that belongs to another tenant', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(1, 1);
    $storage = new FakeDocumentStorage;
    $storage->write('documents/sub/1.pdf', 'contenuto-documento');

    expect(fn () => (new PreviewDocumentService($documents, $storage))->preview(1, fakePreviewActor('altro-tenant')))
        ->toThrow(DocumentNotAuthorizedException::class);
});

test('previewOriginal returns the original document bytes and filename (RF56-OB/UC-40.2)', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['file_path' => 'documents/originals/1.pdf', 'original_filename' => 'cedolini-marzo.pdf']);
    $documents->seedSubDocument(1, 1);
    $storage = new FakeDocumentStorage;
    $storage->write('documents/originals/1.pdf', 'contenuto-documento-originale');

    $result = (new PreviewDocumentService($documents, $storage))->previewOriginal(1, fakePreviewActor());

    expect($result->bytes)->toBe('contenuto-documento-originale')
        ->and($result->filename)->toBe('cedolini-marzo.pdf');
});

test('previewOriginal falls back to a default filename when none was recorded', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['file_path' => 'documents/originals/1.pdf']);
    $documents->seedSubDocument(1, 1);
    $storage = new FakeDocumentStorage;
    $storage->write('documents/originals/1.pdf', 'contenuto-documento-originale');

    $result = (new PreviewDocumentService($documents, $storage))->previewOriginal(1, fakePreviewActor());

    expect($result->filename)->toBe('documento-originale.pdf');
});

test('previewOriginal refuses when the original file is missing from storage', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['file_path' => 'documents/originals/1.pdf']);
    $documents->seedSubDocument(1, 1);
    $storage = new FakeDocumentStorage;

    expect(fn () => (new PreviewDocumentService($documents, $storage))->previewOriginal(1, fakePreviewActor()))
        ->toThrow(DocumentPreviewUnavailableException::class);
});

test('previewOriginal refuses a sub-document whose original belongs to another tenant', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['file_path' => 'documents/originals/1.pdf']);
    $documents->seedSubDocument(1, 1);
    $storage = new FakeDocumentStorage;
    $storage->write('documents/originals/1.pdf', 'contenuto-documento-originale');

    expect(fn () => (new PreviewDocumentService($documents, $storage))->previewOriginal(1, fakePreviewActor('altro-tenant')))
        ->toThrow(DocumentNotAuthorizedException::class);
});
