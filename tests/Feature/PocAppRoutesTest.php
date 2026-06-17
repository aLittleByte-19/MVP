<?php

use App\Copilot\Ai\BedrockService;
use App\Copilot\Documents\Enums\ReviewStatus;
use App\Copilot\Workflow\Services\DocumentWorkflowService;
use App\Copilot\Workflow\Services\DocumentWorkflowTaskHandler;
use App\Models\Copilot\AuditEvent;
use App\Models\Copilot\Communication;
use App\Models\Copilot\ExtractedData;
use App\Models\Copilot\OriginalDocument;
use App\Models\Copilot\SubDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

function pocPdfUpload(string $filename = 'cedolino.pdf'): UploadedFile
{
    $pdf = new Fpdi;
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Cedolino aziendale');

    return UploadedFile::fake()->createWithContent($filename, $pdf->Output('S'));
}

function pocMockWorkflowStart(object $test): void
{
    $mock = Mockery::mock(DocumentWorkflowService::class);
    $mock->shouldReceive('start')
        ->once()
        ->andReturnUsing(fn (OriginalDocument $document) => $document);

    app()->instance(DocumentWorkflowService::class, $mock);
}

function pocMockWorkflowNotStarted(): void
{
    $mock = Mockery::mock(DocumentWorkflowService::class);
    $mock->shouldNotReceive('start');

    app()->instance(DocumentWorkflowService::class, $mock);
}

/**
 * @return array{callback_required: bool, output: array<string, mixed>}
 */
function pocRunWorkflowTask(OriginalDocument $document, string $taskType = 'bedrock.extract'): array
{
    return app(DocumentWorkflowTaskHandler::class)->handle([
        'taskToken' => 'test-token-'.$taskType.'-'.$document->id.'-'.str()->uuid(),
        'taskType' => $taskType,
        'documentId' => $document->id,
        'tenantId' => $document->tenant_id,
        'correlationId' => 'test-correlation',
        's3Bucket' => 'poc-test-bucket',
        's3Key' => $document->file_path,
    ]);
}

test('runtime admin console is not exposed', function () {
    $this->get('/admin')
        ->assertNotFound();

    $this->get('/admin/ai-assistant')
        ->assertNotFound();

    $this->get('/admin/login')
        ->assertNotFound();
});

test('api state uses local poc identity in local mode', function () {
    $this->getJson('/api/v1/state')
        ->assertOk()
        ->assertJsonStructure(['assistant', 'copilot']);
});

test('api rejects incomplete trusted identity claims outside local mode', function () {
    config(['poc.identity.mode' => 'trusted_headers']);

    $this->getJson('/api/v1/state')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthorized');
});

test('ai assistant generation uses only prompt tone and style', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('generateCommunication')
            ->once()
            ->with(
                'Comunicazione interna sulla nuova area documentale.',
                'Chiaro e diretto',
                'Testo informativo',
            )
            ->andReturn(['title' => 'Titolo reale', 'body' => 'Corpo reale']);
    });

    $this->postJson('/api/v1/communications', [
        'prompt' => 'Comunicazione interna sulla nuova area documentale.',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ])
        ->assertCreated()
        ->assertJsonPath('communication.title', 'Titolo reale');

    expect(Communication::query()->count())->toBe(1);
    expect(Communication::query()->first()->generated_body)->toBe('Corpo reale')
        ->and(Communication::query()->first()->tenant_id)->toBe('poc-local-tenant')
        ->and(AuditEvent::query()->where('event_type', 'poc-communication-generated')->count())->toBe(1);
});

test('document upload performs initial split and field extraction', function () {
    config([
        'filesystems.default' => 's3',
    ]);

    Queue::fake();
    Storage::fake('s3');
    pocMockWorkflowStart($this);

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('splitDocument')
            ->once()
            ->andReturn([
                ['employee_name' => 'Mario Rossi', 'start_page' => 1, 'end_page' => 1],
            ]);

        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => 'Azienda Demo Srl',
                'document_date' => now()->toDateString(),
                'document_type' => 'Cedolino',
                'description' => 'Cedolino dimostrativo.',
                'confidence_score' => 86,
            ]);
    });

    $uploadResponse = $this->postJson('/api/v1/documents/ocr', ['document' => pocPdfUpload()])
        ->assertStatus(202)
        ->assertJsonStructure(['streamUrl']);

    expect(OriginalDocument::query()->count())->toBe(1);
    $document = OriginalDocument::query()->first();
    Storage::disk('s3')->assertExists($document->file_path);
    Queue::assertNothingPushed();

    // L'OCR di Textract alimenta il classificatore: nei test lo seminiamo a mano.
    $document->update(['ocr_text' => "[Pagina 1]\nMario Rossi - Azienda Demo Srl", 'ocr_confidence_avg' => 97.5]);

    // Run the workflow task manually: this mirrors the SQS callback-token worker path.
    pocRunWorkflowTask($document);

    // Stream finds the document already completed and flushes all results.
    $streamResponse = $this->get($uploadResponse->json('streamUrl'))->assertOk();
    ob_start();
    $streamResponse->baseResponse->sendContent();
    ob_end_clean();

    expect(SubDocument::query()->count())->toBe(1);
    expect(ExtractedData::query()->first()->employee_first_name)->toBe('Mario');

    $subDocument = SubDocument::query()->first();
    $this->get(route('api.v1.documents.preview', $subDocument))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(AuditEvent::query()->where('event_type', 'poc-document-upload-accepted')->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'poc-document-processing-completed')->count())->toBe(1);
});

test('document upload rejects executable files before workflow start', function () {
    Storage::fake('s3');
    pocMockWorkflowNotStarted();

    $this->postJson('/api/v1/documents/ocr', [
        'document' => UploadedFile::fake()->createWithContent('payload.php', '<?php echo "blocked";'),
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect(OriginalDocument::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'poc-document-upload-rejected')->count())->toBe(1);
});

test('document upload rejects files without real pdf magic bytes', function () {
    Storage::fake('s3');
    pocMockWorkflowNotStarted();

    $this->postJson('/api/v1/documents/ocr', [
        'document' => UploadedFile::fake()->createWithContent('fake.pdf', 'not a pdf'),
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect(OriginalDocument::query()->count())->toBe(0);
});

test('document upload rejects encrypted pdf files', function () {
    Storage::fake('s3');
    pocMockWorkflowNotStarted();

    $encryptedPdf = "%PDF-1.4\n1 0 obj\n<< /Encrypt << /Filter /Standard >> >>\nendobj\n%%EOF";

    $this->postJson('/api/v1/documents/ocr', [
        'document' => UploadedFile::fake()->createWithContent('protected.pdf', $encryptedPdf),
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect(OriginalDocument::query()->count())->toBe(0);
});

test('document upload rejects corrupted pdf files with valid magic bytes', function () {
    Storage::fake('s3');
    pocMockWorkflowNotStarted();

    // Firma valida ma struttura inesistente: respinto da qpdf --check quando
    // disponibile, altrimenti dal parse FPDI.
    $corruptedPdf = '%PDF-1.7 '.str_repeat('garbage senza xref ne trailer ', 20);

    $this->postJson('/api/v1/documents/ocr', [
        'document' => UploadedFile::fake()->createWithContent('corrotto.pdf', $corruptedPdf),
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect(OriginalDocument::query()->count())->toBe(0);
});

test('classifier returning no segments yields a single whole-document recipient', function () {
    config([
        'filesystems.default' => 's3',
    ]);

    Queue::fake();
    Storage::fake('s3');
    pocMockWorkflowStart($this);

    // Quando il classificatore non distingue destinatari, l'intero documento
    // diventa un unico destinatario (>=1 garantito), quindi l'estrazione parte.
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('splitDocument')
            ->once()
            ->andReturn([]);

        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => 'Azienda Demo Srl',
                'document_date' => now()->toDateString(),
                'document_type' => 'Autocertificazione',
                'description' => 'Documento a destinatario singolo.',
                'confidence_score' => 88,
            ]);
    });

    $uploadResponse = $this->postJson('/api/v1/documents/ocr', ['document' => pocPdfUpload()])
        ->assertStatus(202)
        ->assertJsonStructure(['streamUrl']);

    $document = OriginalDocument::query()->first();
    $document->update(['ocr_text' => "[Pagina 1]\nMario Rossi - Azienda Demo Srl", 'ocr_confidence_avg' => 97.5]);

    pocRunWorkflowTask($document);

    $subDocument = SubDocument::query()->first();

    expect(SubDocument::query()->count())->toBe(1)
        ->and($subDocument->start_page)->toBe(1)
        ->and($subDocument->end_page)->toBe(1)
        ->and($document->refresh()->processing_status->value)->toBe('completed')
        ->and($document->error_message)->toBeNull();

    $this->get($uploadResponse->json('streamUrl'))->assertOk();
});

test('document processing clamps model page ranges to the uploaded pdf page count', function () {
    config([
        'filesystems.default' => 's3',
    ]);

    Queue::fake();
    Storage::fake('s3');
    pocMockWorkflowStart($this);

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('splitDocument')
            ->once()
            ->andReturn([
                ['employee_name' => 'Mario Rossi', 'start_page' => 5, 'end_page' => 10],
            ]);

        $mock->shouldReceive('extractFields')
            ->once()
            ->andReturn([
                'employee_first_name' => 'Mario',
                'employee_last_name' => 'Rossi',
                'company_name' => 'Azienda Demo Srl',
                'document_date' => now()->toDateString(),
                'document_type' => 'Cedolino',
                'description' => 'Cedolino mensile.',
                'confidence_score' => 90,
            ]);
    });

    $this->postJson('/api/v1/documents/ocr', ['document' => pocPdfUpload()])
        ->assertStatus(202);

    $document = OriginalDocument::query()->first();
    $document->update(['ocr_text' => "[Pagina 1]\nMario Rossi - Azienda Demo Srl", 'ocr_confidence_avg' => 97.5]);
    pocRunWorkflowTask($document);

    $subDocument = SubDocument::query()->first();

    expect($subDocument->start_page)->toBe(1)
        ->and($subDocument->end_page)->toBe(1)
        ->and($document->refresh()->processing_status->value)->toBe('completed')
        ->and($document->error_message)->toBeNull();
});

test('document processing keeps split visible when field extraction fails', function () {
    config([
        'filesystems.default' => 's3',
    ]);

    Queue::fake();
    Storage::fake('s3');
    pocMockWorkflowStart($this);

    $expectedMessage = 'Le credenziali runtime AWS sono scadute. Aggiorna il ruolo applicativo o il segreto runtime in Secrets Manager.';

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('splitDocument')
            ->once()
            ->andReturn([
                ['employee_name' => 'Mario Rossi', 'start_page' => 1, 'end_page' => 1],
            ]);

        $mock->shouldReceive('extractFields')
            ->once()
            ->andThrow(new RuntimeException('ExpiredToken: token expired'));
    });

    $this->postJson('/api/v1/documents/ocr', ['document' => pocPdfUpload()])
        ->assertStatus(202);

    $document = OriginalDocument::query()->first();
    $document->update(['ocr_text' => "[Pagina 1]\nMario Rossi - Azienda Demo Srl", 'ocr_confidence_avg' => 97.5]);
    expect(fn () => pocRunWorkflowTask($document))
        ->toThrow(RuntimeException::class);

    $subDocument = SubDocument::query()->first();
    $extractedData = ExtractedData::query()->first();

    expect(SubDocument::query()->count())->toBe(1)
        ->and($subDocument->error_message)->toBe($expectedMessage)
        ->and($extractedData)->toBeNull()
        ->and($document->refresh()->processing_status->value)->toBe('failed')
        ->and($document->error_message)->toBe($expectedMessage);

    $this->getJson('/api/v1/state')
        ->assertOk()
        ->assertJsonPath('copilot.documents.0.error', $expectedMessage)
        ->assertJsonPath('copilot.documents.0.previewLines.3', 'Errore estrazione: '.$expectedMessage);
});

test('assistant generated metric counts every stored communication', function () {
    Communication::factory()->draft()->create();
    Communication::factory()->discarded()->create();

    $this->getJson('/api/v1/state')
        ->assertOk()
        ->assertJsonPath('assistant.metrics.0.value', 2)
        ->assertJsonPath('assistant.metrics.1.value', 1);
});

test('operator can correct extracted data and mark a sub document as manually validated', function () {
    $subDocument = SubDocument::factory()->create(['review_status' => ReviewStatus::NeedsReview]);
    ExtractedData::factory()->create([
        'sub_document_id' => $subDocument->id,
        'employee_first_name' => 'Maro',
        'employee_last_name' => 'Rossi',
        'confidence_score' => 61,
        'ai_payload' => ['employee_first_name' => 'Maro', 'confidence_score' => 61],
    ]);

    $this->putJson("/api/v1/documents/{$subDocument->id}/extracted-data", [
        'employeeFirstName' => 'Mario',
        'companyName' => 'Acme corretta',
        'documentDate' => '2026-01-31',
        'markAsValidated' => true,
    ])
        ->assertOk()
        ->assertJsonPath('document.employee', 'Mario Rossi')
        ->assertJsonPath('document.reviewStatus', 'manually_validated');

    $subDocument->refresh();
    $data = $subDocument->extractedData()->sole();

    expect($subDocument->review_status)->toBe(ReviewStatus::ManuallyValidated)
        ->and($data->employee_first_name)->toBe('Mario')
        ->and($data->company_name)->toBe('Acme corretta')
        ->and($data->ai_payload['employee_first_name'])->toBe('Maro')
        ->and(AuditEvent::query()->where('event_type', 'poc-sub-document-extracted-data-corrected')->count())->toBe(1);
});

test('operator can mark existing extracted data as reviewed without changing fields', function () {
    $subDocument = SubDocument::factory()->create(['review_status' => ReviewStatus::NeedsReview]);
    ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    $this->postJson("/api/v1/documents/{$subDocument->id}/review")
        ->assertOk()
        ->assertJsonPath('document.reviewStatus', 'manually_validated');

    expect($subDocument->fresh()->review_status)->toBe(ReviewStatus::ManuallyValidated);
});

test('manual review requires extracted data to exist first', function () {
    $subDocument = SubDocument::factory()->create(['review_status' => ReviewStatus::Quarantined]);

    $this->postJson("/api/v1/documents/{$subDocument->id}/review")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

test('manual correction endpoint rejects cross tenant access', function () {
    config(['poc.identity.mode' => 'trusted_headers']);
    $subDocument = SubDocument::factory()->create();

    $this->putJson("/api/v1/documents/{$subDocument->id}/extracted-data", [
        'employeeFirstName' => 'Mario',
    ], [
        'X-Poc-User-Id' => 'operator-b',
        'X-Poc-User-Email' => 'operator-b@example.test',
        'X-Poc-Tenant-Id' => 'another-tenant',
        'X-Poc-Roles' => 'poc-operator',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});
