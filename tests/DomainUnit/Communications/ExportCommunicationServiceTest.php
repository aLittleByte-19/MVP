<?php

use App\Mvp\Communications\Application\UseCases\ExportCommunicationService;
use App\Mvp\Communications\Domain\Exceptions\CommunicationNotReadyForExportException;
use Tests\DomainUnit\Communications\Fakes\FakeCommunicationPdfRenderer;
use Tests\DomainUnit\Communications\Fakes\InMemoryCommunicationRepository;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS). ExportCommunicationService
 * non dipende da AuditLogger/MetricsRecorder: e' gia' testabile cosi' com'e',
 * senza alcuna modifica di produzione.
 */
test('fingerprint and render refuse a communication not ready for export', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['generation_status' => 'processing']);
    $service = new ExportCommunicationService($repository, new FakeCommunicationPdfRenderer);

    expect(fn () => $service->fingerprint(1))->toThrow(CommunicationNotReadyForExportException::class)
        ->and(fn () => $service->render(1))->toThrow(CommunicationNotReadyForExportException::class);
});

test('fingerprint and render refuse a discarded communication even if completed', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['generation_status' => 'completed', 'status' => 'discarded']);
    $service = new ExportCommunicationService($repository, new FakeCommunicationPdfRenderer);

    expect(fn () => $service->fingerprint(1))->toThrow(CommunicationNotReadyForExportException::class);
});

test('fingerprint and render delegate to the renderer once ready', function () {
    $repository = new InMemoryCommunicationRepository;
    $repository->seed(1, ['generation_status' => 'completed', 'status' => 'approved']);
    $service = new ExportCommunicationService($repository, new FakeCommunicationPdfRenderer);

    $rendered = $service->render(1);

    expect($service->fingerprint(1))->toBe('fingerprint-1')
        ->and($rendered->bytes)->toBe('pdf-bytes-1')
        ->and($rendered->filename)->toBe('comunicazione-1.pdf');
});
