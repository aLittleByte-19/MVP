<?php

use App\Mvp\Documents\Application\UseCases\PreviewDocumentService;
use App\Mvp\Documents\Domain\Exceptions\DocumentPreviewUnavailableException;
use Tests\DomainUnit\Documents\Fakes\FakeDocumentStorage;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB). Prima il controller
 * chiamava Storage::disk() direttamente, bypassando DocumentStoragePort:
 * ora l'I/O passa da PreviewDocumentUseCase, testabile in isolamento.
 */
test('preview returns the stored bytes and the original filename', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(1, 1, ['original_filename' => 'busta-paga.pdf']);
    $storage = new FakeDocumentStorage;
    $storage->write('documents/sub/1.pdf', 'contenuto-documento');

    $result = (new PreviewDocumentService($documents, $storage))->preview(1);

    expect($result->bytes)->toBe('contenuto-documento')
        ->and($result->filename)->toBe('busta-paga.pdf');
});

test('preview refuses when the file is missing from storage', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1);
    $documents->seedSubDocument(1, 1);
    $storage = new FakeDocumentStorage;

    expect(fn () => (new PreviewDocumentService($documents, $storage))->preview(1))
        ->toThrow(DocumentPreviewUnavailableException::class);
});
