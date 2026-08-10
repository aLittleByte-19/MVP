<?php

use App\Mvp\Communications\Application\UseCases\ListCommunicationsService;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS). ListCommunicationsService
 * non dipende da AuditLogger/MetricsRecorder: e' gia' testabile cosi' com'e'.
 */
test('list delegates straight to the repository, unchanged', function () {
    $service = new ListCommunicationsService(new InMemoryCommunicationRepository);

    $page = $service->list('tenant-1', ['keyword' => 'ferie'], 2, 15);

    expect($page)->toBe(['ids' => [], 'total' => 0, 'page' => 2, 'perPage' => 15]);
});
