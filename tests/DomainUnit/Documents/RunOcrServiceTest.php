<?php

use App\Mvp\Documents\Application\UseCases\RunOcrService;
use App\Mvp\Documents\Domain\Events\DocumentProcessingFailed;
use Tests\DomainUnit\Documents\Fakes\FakeOcrGateway;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;
use Tests\DomainUnit\Documents\Fakes\RecordingDocumentEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS). RunOcrService non
 * dipende da AuditLogger/MetricsRecorder: e' gia' testabile cosi' com'e'.
 */
test('run persists the OCR result when Textract is enabled', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['s3_bucket' => 'bucket-test', 's3_key' => 'key-test']);
    $ocr = new FakeOcrGateway;
    $ocr->willReturn(['enabled' => true, 'jobId' => 'job-123', 'text' => 'testo estratto', 'pages' => [], 'confidenceAvg' => 97.5]);
    $events = new RecordingDocumentEventDispatcher;

    $result = (new RunOcrService($documents, $ocr, $events))->run(1, null, null);

    expect($result)->toBe(['skipped' => false, 'jobId' => 'job-123', 'confidenceAvg' => 97.5])
        ->and($documents->findOriginalDocument(1)->ocrText)->toBe('testo estratto');
});

test('run skips persistence when Textract is disabled', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['s3_bucket' => 'bucket-test', 's3_key' => 'key-test']);
    $ocr = new FakeOcrGateway;
    $ocr->willReturn(['enabled' => false, 'jobId' => null, 'text' => null, 'pages' => [], 'confidenceAvg' => null]);
    $events = new RecordingDocumentEventDispatcher;

    $result = (new RunOcrService($documents, $ocr, $events))->run(1, null, null);

    expect($result['skipped'])->toBeTrue()
        ->and($documents->findOriginalDocument(1)->ocrText)->toBeNull();
});

test('run skips Textract entirely when the document is already completed', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['s3_bucket' => 'bucket-test', 's3_key' => 'key-test', 'processing_status' => 'completed']);
    $ocr = new FakeOcrGateway;
    $ocr->willReturn(['enabled' => true, 'jobId' => 'job-should-not-run', 'text' => 'non dovrebbe arrivare qui', 'pages' => [], 'confidenceAvg' => 99.0]);
    $events = new RecordingDocumentEventDispatcher;

    $result = (new RunOcrService($documents, $ocr, $events))->run(1, null, null);

    expect($result)->toBe(['skipped' => true, 'jobId' => null, 'confidenceAvg' => null])
        ->and($documents->findOriginalDocument(1)->ocrText)->toBeNull();
});

test('run marks the document as failed and dispatches DocumentProcessingFailed when Textract throws', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['s3_bucket' => 'bucket-test', 's3_key' => 'key-test']);
    $ocr = new FakeOcrGateway;
    $ocr->willThrow(new RuntimeException('Textract non raggiungibile'));
    $events = new RecordingDocumentEventDispatcher;

    expect(fn () => (new RunOcrService($documents, $ocr, $events))->run(1, null, null))
        ->toThrow(RuntimeException::class, 'Textract non raggiungibile');

    expect($documents->findOriginalDocument(1)->processingStatus)->toBe('failed')
        ->and($events->hasDispatched(DocumentProcessingFailed::class))->toBeTrue();
});
