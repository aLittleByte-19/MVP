<?php

use App\Models\Communication;
use setasign\Fpdi\Fpdi;

/**
 * Verifica solo la ben-formattezza del PDF (numero di pagine leggibile), non
 * un match esatto sul contenuto: e' lo stesso livello di dettaglio usato per
 * gli altri export PDF del progetto (vedi mvpAssertWellFormedPdf in
 * MvpAppRoutesTest.php, qui duplicato con nome diverso perche' Pest non
 * garantisce l'ordine di caricamento dei file quando la suite gira filtrata
 * su un singolo file).
 */
function assistantReportAssertWellFormedPdf(string $bytes): void
{
    $path = tempnam(sys_get_temp_dir(), 'mvp-assistant-report-test-');
    file_put_contents($path, $bytes);

    $pdf = new Fpdi;
    $pageCount = $pdf->setSourceFile($path);

    @unlink($path);

    expect($pageCount)->toBeGreaterThanOrEqual(1);
}

test('the assistant metrics report is exported as a well-formed pdf attachment', function () {
    Communication::factory()->approved()->rated(5, 'Ottima bozza.')->create();
    Communication::factory()->approved()->rated(3)->create();

    $response = $this->get('/api/v1/assistant/metrics/export')->assertOk();

    $response->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('.pdf');

    assistantReportAssertWellFormedPdf($response->getContent());
});

test('a tenant with no communications still gets a valid report', function () {
    $response = $this->get('/api/v1/assistant/metrics/export')->assertOk();

    assistantReportAssertWellFormedPdf($response->getContent());
});
