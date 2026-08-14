<?php

use App\Mvp\Documents\Application\UseCases\ListDocumentsService;
use App\Mvp\Documents\Domain\ValueObjects\DocumentListFilters;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS). ListDocumentsService
 * non dipende da AuditLogger/MetricsRecorder: e' gia' testabile cosi' com'e'.
 */
test('list delegates straight to the repository, unchanged', function () {
    $service = new ListDocumentsService(new InMemoryDocumentRepository);

    $page = $service->list('tenant-1', new DocumentListFilters(search: 'Rossi'), 3, 25);

    expect($page->subDocumentIds)->toBe([])
        ->and($page->total)->toBe(0)
        ->and($page->page)->toBe(3)
        ->and($page->perPage)->toBe(25);
});
