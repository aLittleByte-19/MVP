<?php

use App\Exceptions\InvalidAiOutputException;
use App\Mvp\Documents\Application\UseCases\ProcessDocumentService;
use App\Mvp\Documents\Domain\Events\AiOutputRejected;
use App\Mvp\Documents\Domain\Events\SubDocumentFieldsExtracted;
use Psr\Log\NullLogger;
use Tests\DomainUnit\Documents\Fakes\FakeDocumentAiGateway;
use Tests\DomainUnit\Documents\Fakes\FakeDocumentStorage;
use Tests\DomainUnit\Documents\Fakes\FakeUniqueIdGenerator;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;
use Tests\DomainUnit\Documents\Fakes\NullWorkflowTaskHeartbeat;
use Tests\DomainUnit\Documents\Fakes\RecordingDocumentEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS, vedi refactory.md
 * Compito 3 punto 2). Copre extractAndSaveFields(), non process(): quest'ultimo
 * manipola PDF reali via Fpdi e chiama storage_path(), un helper Laravel che
 * richiede il container — resta testato solo a livello Feature.
 */
function processDocumentService(
    InMemoryDocumentRepository $documents,
    FakeDocumentAiGateway $ai,
    RecordingDocumentEventDispatcher $events,
): ProcessDocumentService {
    return new ProcessDocumentService(
        $documents,
        new FakeDocumentStorage,
        $ai,
        $events,
        new NullWorkflowTaskHeartbeat,
        new NullLogger,
        new FakeUniqueIdGenerator,
        80,
    );
}

test('extractAndSaveFields saves the AI fields and dispatches SubDocumentFieldsExtracted', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['ocr_confidence_avg' => 95.0, 'ocr_text' => '[Pagina 1]\nMario Rossi']);
    $documents->seedSubDocument(10, 1, ['start_page' => 1, 'end_page' => 1]);
    $ai = new FakeDocumentAiGateway;
    $ai->willReturnFields([
        'employee_first_name' => 'Mario',
        'employee_last_name' => 'Rossi',
        'company_name' => 'Acme Srl',
        'document_date' => '2026-01-15',
        'document_type' => 'cedolino',
        'description' => null,
        'confidence_score' => null,
    ]);
    $events = new RecordingDocumentEventDispatcher;

    processDocumentService($documents, $ai, $events)->extractAndSaveFields(10);

    $extracted = $documents->extractedDataFor(10);

    expect($extracted['company_name'])->toBe('Acme Srl')
        ->and($extracted['confidence_score'])->toBe(95)
        ->and($events->hasDispatched(SubDocumentFieldsExtracted::class))->toBeTrue();
});

test('extractAndSaveFields quarantines the sub-document and dispatches AiOutputRejected on invalid AI output', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['ocr_confidence_avg' => 95.0, 'ocr_text' => '[Pagina 1]\nMario Rossi']);
    $documents->seedSubDocument(10, 1);
    $ai = new FakeDocumentAiGateway;
    $ai->willThrowOnExtract(new InvalidAiOutputException('extract_fields', ['company_name is required']));
    $events = new RecordingDocumentEventDispatcher;

    processDocumentService($documents, $ai, $events)->extractAndSaveFields(10);

    expect($documents->extractedDataFor(10))->toBeNull()
        ->and($events->hasDispatched(AiOutputRejected::class))->toBeTrue()
        ->and($events->hasDispatched(SubDocumentFieldsExtracted::class))->toBeFalse();
});

test('manual metadata overrides the AI output before computing confidence', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, [
        'ocr_confidence_avg' => 100.0,
        'ocr_text' => '[Pagina 1]\nMario Rossi',
        'manual_company_name' => 'Dichiarata Srl',
    ]);
    $documents->seedSubDocument(10, 1);
    $ai = new FakeDocumentAiGateway;
    $ai->willReturnFields([
        'employee_first_name' => 'Mario',
        'employee_last_name' => 'Rossi',
        'company_name' => 'Estratta Srl',
        'document_date' => '2026-01-15',
        'document_type' => null,
        'description' => null,
        'confidence_score' => null,
    ]);
    $events = new RecordingDocumentEventDispatcher;

    processDocumentService($documents, $ai, $events)->extractAndSaveFields(10);

    // company_name dichiarato in upload prevale, ed esce dal calcolo della
    // confidenza (non e' un campo lasciato al modello).
    expect($documents->extractedDataFor(10)['company_name'])->toBe('Dichiarata Srl');
});
