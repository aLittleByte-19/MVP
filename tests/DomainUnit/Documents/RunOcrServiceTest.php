<?php

use App\Mvp\Documents\Application\UseCases\RunOcrService;
use Tests\DomainUnit\Documents\Fakes\FakeOcrGateway;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS). RunOcrService non
 * dipende da AuditLogger/MetricsRecorder: e' gia' testabile cosi' com'e'.
 */
test('run persists the OCR result when Textract is enabled', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['s3_bucket' => 'bucket-test', 's3_key' => 'key-test']);
    $ocr = new FakeOcrGateway;
    $ocr->willReturn(['enabled' => true, 'jobId' => 'job-123', 'text' => 'testo estratto', 'pages' => [], 'confidenceAvg' => 97.5]);

    $result = (new RunOcrService($documents, $ocr))->run(1, null, null);

    expect($result)->toBe(['skipped' => false, 'jobId' => 'job-123', 'confidenceAvg' => 97.5])
        ->and($documents->findOriginalDocument(1)->ocrText)->toBe('testo estratto');
});

test('run skips persistence when Textract is disabled', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['s3_bucket' => 'bucket-test', 's3_key' => 'key-test']);
    $ocr = new FakeOcrGateway;
    $ocr->willReturn(['enabled' => false, 'jobId' => null, 'text' => null, 'pages' => [], 'confidenceAvg' => null]);

    $result = (new RunOcrService($documents, $ocr))->run(1, null, null);

    expect($result['skipped'])->toBeTrue()
        ->and($documents->findOriginalDocument(1)->ocrText)->toBeNull();
});
