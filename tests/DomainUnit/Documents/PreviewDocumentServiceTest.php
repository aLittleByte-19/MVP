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
