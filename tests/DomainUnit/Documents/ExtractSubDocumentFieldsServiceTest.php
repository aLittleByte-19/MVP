<?php

use App\Exceptions\InvalidAiOutputException;
use App\Mvp\Documents\Application\Support\OcrRangeReader;
use App\Mvp\Documents\Application\UseCases\ExtractSubDocumentFieldsService;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Events\AiOutputRejected;
use App\Mvp\Documents\Domain\Events\SubDocumentFieldsExtracted;
use Psr\Log\NullLogger;
use Tests\DomainUnit\Documents\Fakes\FakeDocumentAiGateway;
use Tests\DomainUnit\Documents\Fakes\InMemoryDocumentRepository;
use Tests\DomainUnit\Documents\Fakes\PassthroughTransactionManager;
use Tests\DomainUnit\Documents\Fakes\RecordingDocumentEventDispatcher;

/**
 * Test di dominio puro (nessun bootstrap Laravel/DB/AWS). L'estrazione per
 * destinatario e' un'operazione a se' stante, non solo un dettaglio interno
 * dello split, e non ha bisogno di manipolazione PDF per essere testata
 * (ADR 0010).
 */
function extractSubDocumentFieldsService(
    InMemoryDocumentRepository $documents,
    FakeDocumentAiGateway $ai,
    RecordingDocumentEventDispatcher $events,
): ExtractSubDocumentFieldsService {
    return new ExtractSubDocumentFieldsService(
        $documents,
        $ai,
        $events,
        new NullLogger,
        new OcrRangeReader($ai),
        new PassthroughTransactionManager,
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

    extractSubDocumentFieldsService($documents, $ai, $events)->extractAndSaveFields(10);

    $extracted = $documents->extractedDataFor(10);

    expect($extracted['company_name'])->toBe('Acme Srl')
        ->and($extracted['confidence_score'])->toBe(95)
        ->and($events->hasDispatched(SubDocumentFieldsExtracted::class))->toBeTrue()
        ->and($documents->findSubDocument(10)->reviewStatus())->toBe(ReviewStatus::AutoValidated);
});

test('extractAndSaveFields saves the recipient identifiers read from the document', function () {
    // Codice fiscale, email e matricola erano campi solo manuali: il modello
    // non li chiedeva affatto, e l'operatore li ritrovava vuoti anche quando
    // stavano scritti nel cedolino.
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
        'recipient_email' => 'Mario.Rossi@Acme.it',
        'fiscal_code' => 'rssmra85m12h501w',
        'employee_id' => 'EMP-1042',
        'confidence_score' => null,
    ]);

    extractSubDocumentFieldsService($documents, $ai, new RecordingDocumentEventDispatcher)->extractAndSaveFields(10);

    $extracted = $documents->extractedDataFor(10);

    expect($extracted['recipient_email'])->toBe('mario.rossi@acme.it')
        ->and($extracted['fiscal_code'])->toBe('RSSMRA85M12H501W')
        ->and($extracted['employee_id'])->toBe('EMP-1042');
});

test('extractAndSaveFields drops identifiers that do not hold up to their own format', function () {
    // Un codice fiscale dalla forma sbagliata non e' un'estrazione imprecisa:
    // e' un dato falso, indistinguibile da uno letto davvero. Meglio il campo
    // vuoto, che resta compilabile a mano.
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
        'recipient_email' => 'mario.rossi(at)acme.it',
        'fiscal_code' => 'RSSMRA85M12H501A',
        'employee_id' => null,
        'confidence_score' => null,
    ]);

    extractSubDocumentFieldsService($documents, $ai, new RecordingDocumentEventDispatcher)->extractAndSaveFields(10);

    $extracted = $documents->extractedDataFor(10);

    expect($extracted['recipient_email'])->toBeNull()
        ->and($extracted['fiscal_code'])->toBeNull();
});

test('extractAndSaveFields quarantines the sub-document and dispatches AiOutputRejected on invalid AI output', function () {
    $documents = new InMemoryDocumentRepository;
    $documents->seedOriginal(1, ['ocr_confidence_avg' => 95.0, 'ocr_text' => '[Pagina 1]\nMario Rossi']);
    $documents->seedSubDocument(10, 1);
    $ai = new FakeDocumentAiGateway;
    $ai->willThrowOnExtract(new InvalidAiOutputException('extract_fields', ['company_name is required']));
    $events = new RecordingDocumentEventDispatcher;

    extractSubDocumentFieldsService($documents, $ai, $events)->extractAndSaveFields(10);

    expect($documents->extractedDataFor(10))->toBeNull()
        ->and($events->hasDispatched(AiOutputRejected::class))->toBeTrue()
        ->and($events->hasDispatched(SubDocumentFieldsExtracted::class))->toBeFalse()
        ->and($documents->findSubDocument(10)->reviewStatus())->toBe(ReviewStatus::Quarantined);
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

    extractSubDocumentFieldsService($documents, $ai, $events)->extractAndSaveFields(10);

    // company_name dichiarato in upload prevale, ed esce dal calcolo della
    // confidenza (non e' un campo lasciato al modello).
    expect($documents->extractedDataFor(10)['company_name'])->toBe('Dichiarata Srl');
});
