<?php

use App\Copilot\Ai\BedrockService;
use App\Copilot\Communications\Enums\CommunicationGenerationStatus;
use App\Copilot\Communications\Enums\CoverImageStatus;
use App\Copilot\Communications\Services\CommunicationWorkflowService;
use App\Copilot\Documents\Enums\ReviewStatus;
use App\Copilot\Documents\Services\DocumentWorkflowService;
use App\Copilot\Workflow\Services\WorkflowTaskRunner;
use App\Copilot\Workflow\Support\WorkflowContext;
use App\Models\Copilot\AuditEvent;
use App\Models\Copilot\Communication;
use App\Models\Copilot\ExtractedData;
use App\Models\Copilot\OriginalDocument;
use App\Models\Copilot\SubDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

function mvpPdfUpload(string $filename = 'cedolino.pdf'): UploadedFile
{
    $pdf = new Fpdi;
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Cedolino aziendale');

    return UploadedFile::fake()->createWithContent($filename, $pdf->Output('S'));
}

function mvpMockWorkflowStart(object $test): void
{
    $mock = Mockery::mock(DocumentWorkflowService::class);
    $mock->shouldReceive('start')
        ->once()
        ->andReturnUsing(fn (OriginalDocument $document) => $document);

    app()->instance(DocumentWorkflowService::class, $mock);
}

function mvpMockWorkflowNotStarted(): void
{
    $mock = Mockery::mock(DocumentWorkflowService::class);
    $mock->shouldNotReceive('start');

    app()->instance(DocumentWorkflowService::class, $mock);
}

function mvpMockCommunicationWorkflowStart(object $test): void
{
    $mock = Mockery::mock(CommunicationWorkflowService::class);
    $mock->shouldReceive('start')
        ->once()
        ->andReturnUsing(fn (Communication $communication) => $communication);

    app()->instance(CommunicationWorkflowService::class, $mock);
}

/**
 * @return array{callback_required: bool, output: array<string, mixed>}
 */
function mvpRunCommunicationTask(Communication $communication, string $taskType): array
{
    return app(WorkflowTaskRunner::class)->handle([
        'taskToken' => 'test-token-'.$taskType.'-'.$communication->id.'-'.str()->uuid(),
        'taskType' => $taskType,
        'communicationId' => $communication->id,
        'tenantId' => $communication->tenant_id,
        'correlationId' => 'test-correlation',
    ]);
}

/**
 * @return array{callback_required: bool, output: array<string, mixed>}
 */
function mvpRunWorkflowTask(OriginalDocument $document, string $taskType = 'bedrock.extract'): array
{
    return app(WorkflowTaskRunner::class)->handle([
        'taskToken' => 'test-token-'.$taskType.'-'.$document->id.'-'.str()->uuid(),
        'taskType' => $taskType,
        'documentId' => $document->id,
        'tenantId' => $document->tenant_id,
        'correlationId' => 'test-correlation',
        's3Bucket' => 'mvp-test-bucket',
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

test('api state uses local mvp identity in local mode', function () {
    $this->getJson('/api/v1/state')
        ->assertOk()
        ->assertJsonStructure(['assistant', 'copilot']);
});

test('api rejects incomplete trusted identity claims outside local mode', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);

    $this->getJson('/api/v1/state')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthorized');
});

test('ai assistant generation is accepted and delegated to the communication pipeline', function () {
    mvpMockCommunicationWorkflowStart($this);

    $this->postJson('/api/v1/communications', [
        'prompt' => 'Comunicazione interna sulla nuova area documentale.',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ])
        ->assertAccepted()
        ->assertJsonPath('message', 'Generazione avviata.')
        ->assertJsonStructure(['message', 'communicationId', 'streamUrl']);

    $communication = Communication::query()->sole();

    expect(Communication::query()->count())->toBe(1)
        ->and($communication->prompt)->toBe('Comunicazione interna sulla nuova area documentale.')
        ->and($communication->generation_status)->toBe(CommunicationGenerationStatus::Pending)
        ->and($communication->tenant_id)->toBe('mvp-local-tenant')
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-generation-requested')->count())->toBe(1);
});

test('the communication pipeline generates the text and stores the cover', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->processing()->create();

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('generateCommunication')
            ->once()
            ->andReturn(['title' => 'Titolo reale', 'body' => 'Corpo reale', 'image_prompt' => 'Abstract office motifs, warm palette']);

        // La copertina riceve la direzione visiva scritta dal modello testuale.
        $mock->shouldReceive('generateCommunicationImageWithMeta')
            ->once()
            ->with(Mockery::any(), Mockery::any(), Mockery::any(), 'Abstract office motifs, warm palette')
            ->andReturn([
                'bytes' => 'fake-image-bytes',
                'mime' => 'image/png',
                'warning' => null,
                'reason' => null,
            ]);
    });

    mvpRunCommunicationTask($communication, 'communication.generate_text');
    mvpRunCommunicationTask($communication, 'communication.generate_cover');
    mvpRunCommunicationTask($communication, 'communication.finalize');

    $communication->refresh();

    expect($communication->generated_body)->toBe('Corpo reale')
        ->and($communication->image_prompt)->toBe('Abstract office motifs, warm palette')
        ->and($communication->generation_status)->toBe(CommunicationGenerationStatus::Completed)
        ->and($communication->cover_status)->toBe(CoverImageStatus::Ready)
        ->and($communication->cover_image_path)->toStartWith('communications/covers/')
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-generated')->count())->toBe(1);

    Storage::disk('s3')->assertExists($communication->cover_image_path);
});

test('a failed cover leaves the communication completed with a warning', function () {
    $communication = Communication::factory()->processing()->create([
        'generated_title' => 'Titolo',
        'generated_body' => 'Corpo',
    ]);

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('generateCommunicationImageWithMeta')
            ->once()
            ->andReturn([
                'bytes' => null,
                'mime' => 'image/png',
                'warning' => 'Copertina AI non disponibile: modello immagini Bedrock non configurato.',
                'reason' => 'model_not_configured',
            ]);
    });

    // La copertina degradata non solleva eccezioni: il task chiude con successo.
    $result = mvpRunCommunicationTask($communication, 'communication.generate_cover');
    mvpRunCommunicationTask($communication, 'communication.finalize');

    $communication->refresh();

    expect($result['callback_required'])->toBeTrue()
        ->and($communication->generation_status)->toBe(CommunicationGenerationStatus::Completed)
        ->and($communication->cover_status)->toBe(CoverImageStatus::Failed)
        ->and($communication->cover_error)->toContain('non configurato')
        ->and($communication->cover_image_path)->toBeNull()
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-cover-degraded')->count())->toBe(1);
});

test('a failed text generation fails the whole communication', function () {
    $communication = Communication::factory()->processing()->create();

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('generateCommunication')
            ->once()
            ->andThrow(new RuntimeException('Bedrock non raggiungibile'));
    });

    expect(fn () => mvpRunCommunicationTask($communication, 'communication.generate_text'))
        ->toThrow(RuntimeException::class);

    expect($communication->fresh()->generation_status)->toBe(CommunicationGenerationStatus::Failed);
});

test('workflow audit events keep the correlation id carried by the message', function () {
    $communication = Communication::factory()->processing()->create([
        'generated_title' => 'Titolo',
        'generated_body' => 'Corpo',
    ]);

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('generateCommunicationImageWithMeta')
            ->once()
            ->andReturn([
                'bytes' => null,
                'mime' => 'image/png',
                'warning' => 'Copertina non disponibile.',
                'reason' => 'model_error',
            ]);
    });

    app(WorkflowContext::class)->bind('req-42', 'corr-42', $communication->tenant_id);

    try {
        mvpRunCommunicationTask($communication, 'communication.generate_cover');
    } finally {
        app(WorkflowContext::class)->clear();
    }

    $event = AuditEvent::query()->where('event_type', 'mvp-communication-cover-degraded')->sole();

    expect($event->request_id)->toBe('req-42')
        ->and($event->correlation_id)->toBe('corr-42');
});

test('document upload performs initial split and field extraction', function () {
    config([
        'filesystems.default' => 's3',
    ]);

    Queue::fake();
    Storage::fake('s3');
    mvpMockWorkflowStart($this);

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

    $uploadResponse = $this->postJson('/api/v1/documents/ocr', ['document' => mvpPdfUpload()])
        ->assertStatus(202)
        ->assertJsonStructure(['streamUrl']);

    expect(OriginalDocument::query()->count())->toBe(1);
    $document = OriginalDocument::query()->first();
    Storage::disk('s3')->assertExists($document->file_path);
    Queue::assertNothingPushed();

    // L'OCR di Textract alimenta il classificatore: nei test lo seminiamo a mano.
    $document->update(['ocr_text' => "[Pagina 1]\nMario Rossi - Azienda Demo Srl", 'ocr_confidence_avg' => 97.5]);

    // Run the workflow task manually: this mirrors the SQS callback-token worker path.
    mvpRunWorkflowTask($document);

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

    expect(AuditEvent::query()->where('event_type', 'mvp-document-upload-accepted')->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', 'mvp-document-processing-completed')->count())->toBe(1);
});

test('document upload rejects executable files before workflow start', function () {
    Storage::fake('s3');
    mvpMockWorkflowNotStarted();

    $this->postJson('/api/v1/documents/ocr', [
        'document' => UploadedFile::fake()->createWithContent('payload.php', '<?php echo "blocked";'),
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect(OriginalDocument::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', 'mvp-document-upload-rejected')->count())->toBe(1);
});

test('document upload rejects files without real pdf magic bytes', function () {
    Storage::fake('s3');
    mvpMockWorkflowNotStarted();

    $this->postJson('/api/v1/documents/ocr', [
        'document' => UploadedFile::fake()->createWithContent('fake.pdf', 'not a pdf'),
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect(OriginalDocument::query()->count())->toBe(0);
});

test('document upload rejects encrypted pdf files', function () {
    Storage::fake('s3');
    mvpMockWorkflowNotStarted();

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
    mvpMockWorkflowNotStarted();

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
    mvpMockWorkflowStart($this);

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

    $uploadResponse = $this->postJson('/api/v1/documents/ocr', ['document' => mvpPdfUpload()])
        ->assertStatus(202)
        ->assertJsonStructure(['streamUrl']);

    $document = OriginalDocument::query()->first();
    $document->update(['ocr_text' => "[Pagina 1]\nMario Rossi - Azienda Demo Srl", 'ocr_confidence_avg' => 97.5]);

    mvpRunWorkflowTask($document);

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
    mvpMockWorkflowStart($this);

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

    $this->postJson('/api/v1/documents/ocr', ['document' => mvpPdfUpload()])
        ->assertStatus(202);

    $document = OriginalDocument::query()->first();
    $document->update(['ocr_text' => "[Pagina 1]\nMario Rossi - Azienda Demo Srl", 'ocr_confidence_avg' => 97.5]);
    mvpRunWorkflowTask($document);

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
    mvpMockWorkflowStart($this);

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

    $this->postJson('/api/v1/documents/ocr', ['document' => mvpPdfUpload()])
        ->assertStatus(202);

    $document = OriginalDocument::query()->first();
    $document->update(['ocr_text' => "[Pagina 1]\nMario Rossi - Azienda Demo Srl", 'ocr_confidence_avg' => 97.5]);
    expect(fn () => mvpRunWorkflowTask($document))
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
        ->assertJsonPath('copilot.documents.0.previewLines.1', 'Errore estrazione: '.$expectedMessage);
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
        ->and(AuditEvent::query()->where('event_type', 'mvp-sub-document-extracted-data-corrected')->count())->toBe(1);
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
    config(['mvp.identity.mode' => 'trusted_headers']);
    $subDocument = SubDocument::factory()->create();

    $this->putJson("/api/v1/documents/{$subDocument->id}/extracted-data", [
        'employeeFirstName' => 'Mario',
    ], [
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

test('operator can upload a manual cover image for a communication draft', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->draft()->coverReady()->create();
    $previousPath = $communication->cover_image_path;
    Storage::disk('s3')->put($previousPath, 'vecchia-copertina');

    $response = $this->withHeader('Accept', 'application/json')
        ->post("/api/v1/communications/{$communication->id}/cover-image", [
            'image' => UploadedFile::fake()->image('manual-cover.png', 1280, 720),
        ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Immagine di copertina aggiornata correttamente.')
        ->assertJsonPath('communication.id', $communication->id)
        ->assertJsonPath('communication.coverStatus', 'ready');

    $communication->refresh();

    expect($communication->cover_image_path)->not->toBe($previousPath)
        ->and($communication->cover_image_source->value)->toBe('manual')
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-cover-updated')->count())->toBe(1);

    Storage::disk('s3')->assertExists($communication->cover_image_path);
    // La copertina sostituita non deve restare orfana sullo storage.
    Storage::disk('s3')->assertMissing($previousPath);
});

test('operator can download the cover image of a communication', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->draft()->coverReady()->create();
    Storage::disk('s3')->put($communication->cover_image_path, 'contenuto-copertina');

    $response = $this->get("/api/v1/communications/{$communication->id}/cover-image");

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    expect($response->streamedContent())->toBe('contenuto-copertina');
});

test('cover download returns 404 when the object is missing', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->draft()->coverReady()->create();

    $this->withHeader('Accept', 'application/json')
        ->get("/api/v1/communications/{$communication->id}/cover-image")
        ->assertNotFound();
});

test('operator can remove a manual cover image from a communication draft', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->draft()->coverReady()->create();
    $path = $communication->cover_image_path;
    Storage::disk('s3')->put($path, 'copertina');

    $this->deleteJson("/api/v1/communications/{$communication->id}/cover-image")
        ->assertOk()
        ->assertJsonPath('message', 'Immagine di copertina rimossa.')
        ->assertJsonPath('communication.id', $communication->id)
        ->assertJsonPath('communication.coverImageUrl', null)
        ->assertJsonPath('communication.coverStatus', 'removed');

    expect($communication->fresh()->cover_image_path)->toBeNull()
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-cover-removed')->count())->toBe(1);

    Storage::disk('s3')->assertMissing($path);
});

test('manual cover upload rejects cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $communication = Communication::factory()->draft()->create();

    $this->withHeaders([
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])->post("/api/v1/communications/{$communication->id}/cover-image", [
        'image' => UploadedFile::fake()->image('manual-cover.png', 1280, 720),
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

test('manual cover upload validates image payload', function () {
    $communication = Communication::factory()->draft()->create();

    $this->withHeader('Accept', 'application/json')
        ->post("/api/v1/communications/{$communication->id}/cover-image", [
            'image' => UploadedFile::fake()->createWithContent('cover.txt', 'not an image'),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});
