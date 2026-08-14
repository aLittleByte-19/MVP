<?php

use App\Mvp\Communications\Application\UseCases\ListCommunicationsService;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationListFilters;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS). ListCommunicationsService
 * non dipende da AuditLogger/MetricsRecorder: e' gia' testabile cosi' com'e'.
 */
test('list delegates straight to the repository, unchanged', function () {
    $service = new ListCommunicationsService(new InMemoryCommunicationRepository);

    $page = $service->list('tenant-1', new CommunicationListFilters(keyword: 'ferie'), 2, 15);

    expect($page->communicationIds)->toBe([])
        ->and($page->total)->toBe(0)
        ->and($page->page)->toBe(2)
        ->and($page->perPage)->toBe(15);
});
