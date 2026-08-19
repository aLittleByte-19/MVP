<?php

use App\Models\AuditEvent;
use App\Models\Communication;
use App\Models\ExtractedData;
use App\Models\OriginalDocument;
use App\Models\SubDocument;
use App\Mvp\Ai\BedrockService;
use App\Mvp\Communications\Domain\Enums\CommunicationGenerationStatus;
use App\Mvp\Communications\Domain\Enums\CommunicationStatus;
use App\Mvp\Communications\Domain\Enums\CoverImageStatus;
use App\Mvp\Communications\Domain\Ports\Inbound\StartCommunicationWorkflowUseCase;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Documents\Domain\Enums\SendStatus;
use App\Mvp\Workflow\Ports\Outbound\WorkflowEnginePort;
use App\Mvp\Workflow\Services\WorkflowTaskRunner;
use App\Mvp\Workflow\Support\WorkflowContext;
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

function mvpAssertWellFormedPdf(string $bytes): void
{
    $path = tempnam(sys_get_temp_dir(), 'mvp-pdf-test-');
    file_put_contents($path, $bytes);

    $pdf = new Fpdi;
    $pageCount = $pdf->setSourceFile($path);

    @unlink($path);

    expect($pageCount)->toBeGreaterThanOrEqual(1);
}

function mvpMockWorkflowStart(object $test): void
{
    // Prima, l'intero DocumentWorkflowService era mockato: il suo guard di
    // configurazione non veniva mai eseguito. Ora si mocka solo la porta
    // secondaria (WorkflowEnginePort): StartDocumentWorkflowService gira per
    // davvero, quindi la configurazione minima deve essere presente.
    config([
        'services.workflow.state_machine_arn' => config('services.workflow.state_machine_arn') ?: 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline',
        'services.workflow.task_queue_url' => config('services.workflow.task_queue_url') ?: 'http://localstack:4566/000000000000/mvp-documents',
    ]);

    $mock = Mockery::mock(WorkflowEnginePort::class);
    $mock->shouldReceive('startExecution')
        ->once()
        ->andReturn('arn:aws:states:eu-north-1:000000000000:execution:fake:mvp-doc-test');

    app()->instance(WorkflowEnginePort::class, $mock);
}

function mvpMockWorkflowNotStarted(): void
{
    $mock = Mockery::mock(WorkflowEnginePort::class);
    $mock->shouldNotReceive('startExecution');

    app()->instance(WorkflowEnginePort::class, $mock);
}

function mvpMockCommunicationWorkflowStart(object $test): void
{
    // Prima, l'intero CommunicationWorkflowService era mockato: il suo guard
    // di configurazione non veniva mai eseguito. Ora si mocka solo la porta
    // secondaria (WorkflowEnginePort): StartCommunicationWorkflowService gira
    // per davvero, quindi la configurazione minima deve essere presente.
    config([
        'services.workflow.communications_state_machine_arn' => config('services.workflow.communications_state_machine_arn') ?: 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-communication-pipeline',
        'services.workflow.communications_task_queue_url' => config('services.workflow.communications_task_queue_url') ?: 'http://localstack:4566/000000000000/mvp-communications',
    ]);

    $mock = Mockery::mock(WorkflowEnginePort::class);
    $mock->shouldReceive('startExecution')
        ->once()
        ->andReturn('arn:aws:states:eu-north-1:000000000000:execution:fake:mvp-comm-test');

    app()->instance(WorkflowEnginePort::class, $mock);
}

function mvpMockCommunicationWorkflowRegenerate(object $test): void
{
    // A differenza di start(), regenerate() puo' incontrare una copertina
    // gia' presente e la elimina davvero tramite la porta secondaria: prima
    // l'intero servizio era mockato e questa chiamata di storage restava
    // invisibile al test.
    Storage::fake('s3');

    mvpMockCommunicationWorkflowStart($test);
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
        // Il workflow reale (solo la porta secondaria e' mockata) porta lo
        // stato a Processing dopo l'avvio: prima l'intero servizio era
        // mockato e questo passaggio restava invisibile al test.
        ->and($communication->generation_status)->toBe(CommunicationGenerationStatus::Processing)
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

// Tono e stile sono le uniche leve di direzione dell'AI Assistant: se il task
// smettesse di inoltrarli, la generazione continuerebbe a funzionare ma
// ignorerebbe le scelte dell'utente, e nessun altro test se ne accorgerebbe.
test('the text generation forwards prompt, tone and style to bedrock', function () {
    $communication = Communication::factory()->processing()->create([
        'prompt' => 'Comunicazione interna sulla nuova area documentale.',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ]);

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('generateCommunication')
            ->once()
            ->with(
                'Comunicazione interna sulla nuova area documentale.',
                'Chiaro e diretto',
                'Testo informativo',
            )
            ->andReturn([
                'title' => 'Titolo reale',
                'body' => 'Corpo reale',
                'image_prompt' => 'Direzione visiva',
            ]);
    });

    mvpRunCommunicationTask($communication, 'communication.generate_text');

    expect($communication->refresh()->generated_body)->toBe('Corpo reale');
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

test('workflow runner rejects a message whose tenantId does not match the communication', function () {
    $communication = Communication::factory()->processing()->create(['tenant_id' => 'tenant-owner']);

    expect(fn () => app(WorkflowTaskRunner::class)->handle([
        'taskToken' => 'token-tenant-mismatch',
        'taskType' => 'communication.generate_text',
        'communicationId' => $communication->id,
        'tenantId' => 'tenant-intruder',
    ]))->toThrow(InvalidArgumentException::class, 'tenantId non corrisponde alla comunicazione');
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

test('document upload stores manual metadata and preserves it over AI extraction', function () {
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
                'company_name' => 'Valore AI',
                'document_date' => '2024-01-15',
                'document_type' => 'lettera',
                'description' => 'Estratto AI',
                'confidence_score' => 86,
            ]);
    });

    $this->post('/api/v1/documents/ocr', [
        'document' => mvpPdfUpload(),
        'documentType' => 'cedolino',
        'companyName' => 'Acme Srl',
        'month' => 3,
        'year' => 2026,
    ])->assertStatus(202);

    $document = OriginalDocument::query()->first();

    expect($document->manual_document_type)->toBe('cedolino')
        ->and($document->manual_company_name)->toBe('Acme Srl')
        ->and($document->manual_reference_month)->toBe(3)
        ->and($document->manual_reference_year)->toBe(2026)
        ->and($document->hasManualUploadMetadata())->toBeTrue();

    $document->update(['ocr_text' => "[Pagina 1]\nMario Rossi", 'ocr_confidence_avg' => 97.5]);
    mvpRunWorkflowTask($document);

    $extracted = ExtractedData::query()->first();

    expect($extracted->document_type)->toBe('cedolino')
        ->and($extracted->company_name)->toBe('Acme Srl')
        ->and($extracted->document_date?->toDateString())->toBe('2026-03-01')
        ->and($extracted->ai_payload['document_type'])->toBe('lettera')
        ->and($extracted->ai_payload['company_name'])->toBe('Valore AI');
});

test('document upload treats blank manual metadata as absent', function () {
    config([
        'filesystems.default' => 's3',
    ]);

    Queue::fake();
    Storage::fake('s3');
    mvpMockWorkflowStart($this);

    $this->post('/api/v1/documents/ocr', [
        'document' => mvpPdfUpload(),
        'documentType' => '',
        'companyName' => '   ',
    ])->assertStatus(202);

    $document = OriginalDocument::query()->first();

    expect($document->manual_document_type)->toBeNull()
        ->and($document->manual_company_name)->toBeNull()
        ->and($document->manual_reference_month)->toBeNull()
        ->and($document->manual_reference_year)->toBeNull()
        ->and($document->hasManualUploadMetadata())->toBeFalse();
});

test('document upload rejects invalid manual metadata values', function () {
    Storage::fake('s3');
    mvpMockWorkflowNotStarted();

    $this->post('/api/v1/documents/ocr', [
        'document' => mvpPdfUpload(),
        'documentType' => 'tipologia-non-valida',
        'month' => 13,
        'year' => 1800,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    expect(OriginalDocument::query()->count())->toBe(0);
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
    Communication::factory()->draft()->rated(5)->create();
    Communication::factory()->draft()->rated(3, 'Ok')->create();

    $this->getJson('/api/v1/state')
        ->assertOk()
        ->assertJsonPath('assistant.metrics.0.value', 4)
        ->assertJsonPath('assistant.metrics.1.value', 3)
        ->assertJsonPath('assistant.metrics.2.value', 2)
        ->assertJsonPath('assistant.metrics.2.label', 'Valutazioni ricevute')
        ->assertJsonPath('assistant.metrics.3.value', '4.0')
        ->assertJsonPath('assistant.metrics.3.label', 'Media stelle');
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

test('operator can preview a processed sub-document PDF', function () {
    Storage::fake('s3');
    config(['mvp.documents.storage_disk' => 's3']);

    $subDocument = SubDocument::factory()->create();
    Storage::disk('s3')->put($subDocument->file_path, 'contenuto-documento');

    $response = $this->get("/api/v1/documents/{$subDocument->id}/preview");

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Disposition'))->toContain('inline');
    expect($response->getContent())->toBe('contenuto-documento');
});

test('sub-document preview returns 404 when the file is missing', function () {
    Storage::fake('s3');
    config(['mvp.documents.storage_disk' => 's3']);

    $subDocument = SubDocument::factory()->create();

    $this->withHeader('Accept', 'application/json')
        ->get("/api/v1/documents/{$subDocument->id}/preview")
        ->assertNotFound();
});

test('sub-document preview rejects cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    Storage::fake('s3');
    config(['mvp.documents.storage_disk' => 's3']);

    $subDocument = SubDocument::factory()->create();
    Storage::disk('s3')->put($subDocument->file_path, 'contenuto-documento');

    $this->withHeaders([
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])->get("/api/v1/documents/{$subDocument->id}/preview")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

test('send message fields are composed from extracted data', function () {
    $subDocument = SubDocument::factory()->create();
    ExtractedData::factory()->create([
        'sub_document_id' => $subDocument->id,
        'employee_first_name' => 'Mario',
        'employee_last_name' => 'Rossi',
        'company_name' => 'Acme S.r.l.',
        'document_type' => 'Cedolino',
    ]);

    $response = $this->getJson('/api/v1/state');

    $document = collect($response->json('copilot.documents'))->firstWhere('id', 'sub-'.$subDocument->id);

    expect($document['sendRecipient'])->toBe('Mario Rossi')
        ->and($document['sendSubject'])->toBe('Invio documento — Cedolino')
        ->and($document['sendBody'])->toContain('Gentile Mario Rossi,')
        ->and($document['sendBody'])->toContain('Acme S.r.l.');
});

test('send message fields fall back gracefully without extracted data', function () {
    $subDocument = SubDocument::factory()->create();

    $response = $this->getJson('/api/v1/state');

    $document = collect($response->json('copilot.documents'))->firstWhere('id', 'sub-'.$subDocument->id);

    expect($document['sendRecipient'])->toBe('Destinatario non disponibile')
        ->and($document['sendSubject'])->toBe('Invio documento');
});

test('operator can correct the send message recipient, subject and body', function () {
    $subDocument = SubDocument::factory()->create();
    ExtractedData::factory()->create([
        'sub_document_id' => $subDocument->id,
        'employee_first_name' => 'Mario',
        'employee_last_name' => 'Rossi',
        'document_type' => 'Cedolino',
    ]);

    $this->putJson("/api/v1/documents/{$subDocument->id}/send-message", [
        'recipient' => 'mario.rossi@example.test',
        'subject' => 'Oggetto corretto a mano',
        'body' => 'Testo corretto a mano.',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Messaggio di invio aggiornato.')
        ->assertJsonPath('document.sendRecipient', 'mario.rossi@example.test')
        ->assertJsonPath('document.sendSubject', 'Oggetto corretto a mano')
        ->assertJsonPath('document.sendBody', 'Testo corretto a mano.');

    expect($subDocument->fresh()->send_recipient_override)->toBe('mario.rossi@example.test')
        ->and($subDocument->fresh()->send_subject_override)->toBe('Oggetto corretto a mano')
        ->and($subDocument->fresh()->send_body_override)->toBe('Testo corretto a mano.');
});

test('send message correction is partial and leaves other fields untouched', function () {
    $subDocument = SubDocument::factory()->create();
    ExtractedData::factory()->create([
        'sub_document_id' => $subDocument->id,
        'employee_first_name' => 'Mario',
        'employee_last_name' => 'Rossi',
        'document_type' => 'Cedolino',
    ]);

    $this->putJson("/api/v1/documents/{$subDocument->id}/send-message", [
        'subject' => 'Solo oggetto corretto',
    ])->assertOk();

    $response = $this->getJson('/api/v1/state');
    $document = collect($response->json('copilot.documents'))->firstWhere('id', 'sub-'.$subDocument->id);

    expect($document['sendSubject'])->toBe('Solo oggetto corretto')
        ->and($document['sendRecipient'])->toBe('Mario Rossi');
});

test('send message correction rejects cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $subDocument = SubDocument::factory()->create();

    $this->withHeaders([
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])->putJson("/api/v1/documents/{$subDocument->id}/send-message", [
        'subject' => 'Tentativo non autorizzato',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

test('send message correction validates field lengths', function () {
    $subDocument = SubDocument::factory()->create();

    $this->putJson("/api/v1/documents/{$subDocument->id}/send-message", [
        'subject' => str_repeat('a', 256),
    ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

test('operator can preview and export the precompiled send message', function () {
    $subDocument = SubDocument::factory()->create();
    ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    $preview = $this->get("/api/v1/documents/{$subDocument->id}/send-preview");
    $preview->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($preview->headers->get('Content-Disposition'))->toContain('inline');

    $export = $this->get("/api/v1/documents/{$subDocument->id}/send-export");
    $export->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($export->headers->get('Content-Disposition'))->toContain('attachment');

    mvpAssertWellFormedPdf($preview->getContent());
    mvpAssertWellFormedPdf($export->getContent());
});

test('operator can edit title and body of a draft communication', function () {
    $communication = Communication::factory()->draft()->create([
        'generated_title' => 'Titolo originale',
        'generated_body' => 'Corpo originale',
    ]);

    $this->putJson("/api/v1/communications/{$communication->id}", [
        'title' => 'Titolo corretto a mano',
        'body' => 'Corpo corretto a mano',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Bozza aggiornata.')
        ->assertJsonPath('communication.title', 'Titolo corretto a mano');

    expect($communication->refresh()->generated_title)->toBe('Titolo corretto a mano')
        ->and($communication->generated_body)->toBe('Corpo corretto a mano')
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-edited')->count())->toBe(1);
});

test('editing is rejected once the draft is no longer in draft state', function () {
    // La modifica manuale vale finche' la bozza e' in lavorazione: una volta
    // scartata resta tracciata ma non piu' modificabile.
    $communication = Communication::factory()->discarded()->create();

    $this->withHeader('Accept', 'application/json')
        ->putJson("/api/v1/communications/{$communication->id}", [
            'title' => 'Titolo',
            'body' => 'Corpo',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

test('editing validates the length of title and body', function () {
    $communication = Communication::factory()->draft()->create();

    $this->withHeader('Accept', 'application/json')
        ->putJson("/api/v1/communications/{$communication->id}", [
            'title' => str_repeat('a', 256),
            'body' => 'Corpo valido',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    $this->withHeader('Accept', 'application/json')
        ->putJson("/api/v1/communications/{$communication->id}", [
            'title' => 'Titolo valido',
            'body' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

test('editing rejects cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $communication = Communication::factory()->draft()->create();

    $this->withHeaders([
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])->putJson("/api/v1/communications/{$communication->id}", [
        'title' => 'Titolo',
        'body' => 'Corpo',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect($communication->refresh()->generated_title)->not->toBe('Titolo');
});

test('the communication index only returns communications saved to history, not drafts or discarded ones', function () {
    Communication::factory()->draft()->create();
    Communication::factory()->discarded()->create();
    Communication::factory()->approved()->create();

    $response = $this->getJson('/api/v1/communications')->assertOk();

    expect($response->json('total'))->toBe(1);
});

test('the communication index filters by keyword, tone and style', function () {
    $target = Communication::factory()->approved()->create([
        'prompt' => 'Comunicazione sulla nuova area documentale',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ]);
    Communication::factory()->approved()->create([
        'prompt' => 'Avviso ferie estive',
        'tone' => 'Più istituzionale',
        'style' => 'Comunicato',
    ]);

    $this->getJson('/api/v1/communications?keyword=documentale')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.id', $target->id);

    $this->getJson('/api/v1/communications?tone='.urlencode('Chiaro e diretto'))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.id', $target->id);

    $this->getJson('/api/v1/communications?style='.urlencode('Comunicato'))
        ->assertOk()
        ->assertJsonPath('total', 1);
});

test('the communication index filters by creation day', function () {
    $old = Communication::factory()->approved()->create();
    $old->forceFill(['created_at' => '2025-03-04 10:00:00'])->save();
    Communication::factory()->approved()->create();

    $this->getJson('/api/v1/communications?date=2025-03-04')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.id', $old->id);
});

test('the communication index only returns saved communications of the caller tenant', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);

    Communication::factory()->approved()->create(['tenant_id' => 'mvp-local-tenant']);
    Communication::factory()->approved()->create(['tenant_id' => 'another-tenant']);

    $this->withHeaders([
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-a',
        'X-Mvp-User-Email' => 'operator-a@example.test',
        'X-Mvp-Tenant-Id' => 'mvp-local-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])->getJson('/api/v1/communications')
        ->assertOk()
        ->assertJsonPath('total', 1);
});

test('the communication index rejects a malformed date filter', function () {
    $this->withHeader('Accept', 'application/json')
        ->getJson('/api/v1/communications?date=04-03-2025')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

test('state metrics expose stable keys alongside the presentation label', function () {
    $response = $this->getJson('/api/v1/state')->assertOk();

    $keys = collect($response->json('assistant.metrics'))
        ->merge($response->json('copilot.metrics'))
        ->pluck('key');

    expect($keys)->toContain(
        'assistant.total',
        'assistant.drafts',
        'assistant.rated',
        'assistant.rating_average',
        'copilot.documents',
        'copilot.sub_documents',
        'copilot.needs_review',
        'copilot.validated',
        'copilot.quarantined',
    );
});

test('document metrics report the operational signals of the pipeline', function () {
    // Gli stessi segnali dei gauge letti dalle dashboard Grafana — quanto e' in
    // lavorazione, quanto e' fermo oltre il tempo previsto, quanto e' fallito —
    // ristretti al tenant di chi guarda.
    config()->set('mvp.document_limits.processing_timeout_seconds', 600);

    OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Processing,
        'workflow_started_at' => now()->subMinutes(30),
    ]);
    OriginalDocument::factory()->create([
        'processing_status' => ProcessingStatus::Processing,
        'workflow_started_at' => now()->subMinute(),
    ]);
    OriginalDocument::factory()->create(['processing_status' => ProcessingStatus::Pending]);
    OriginalDocument::factory()->failed()->create();
    OriginalDocument::factory()->completed()->create([
        'workflow_started_at' => now()->subSeconds(40),
        'workflow_completed_at' => now()->subSeconds(10),
    ]);

    $metrics = collect($this->getJson('/api/v1/state')->json('copilot.metrics'))->keyBy('key');

    expect($metrics['copilot.in_progress']['value'])->toBe(3)
        ->and($metrics['copilot.processing_stuck']['value'])->toBe(1)
        ->and($metrics['copilot.processing_failed']['value'])->toBe(1)
        ->and($metrics['copilot.processing_seconds']['value'])->toBe(30)
        ->and($metrics['copilot.processing_seconds']['unit'])->toBe('s');
});

test('an average duration past ninety seconds is expressed in minutes', function () {
    // "312 s" e' un numero da convertire a mente; sotto il minuto e mezzo, al
    // contrario, i minuti con un decimale darebbero una precisione inventata.
    OriginalDocument::factory()->completed()->create([
        'workflow_started_at' => now()->subMinutes(4),
        'workflow_completed_at' => now(),
        'ocr_confidence_avg' => 91.4,
    ]);

    $metrics = collect($this->getJson('/api/v1/state')->json('copilot.metrics'))->keyBy('key');

    expect($metrics['copilot.processing_seconds']['value'])->toBe('4.0')
        ->and($metrics['copilot.processing_seconds']['unit'])->toBe('min')
        ->and($metrics['copilot.ocr_confidence']['value'])->toBe('91.4')
        ->and($metrics['copilot.ocr_confidence']['unit'])->toBe('%');
});

test('a metric without any measurement exposes the placeholder instead of a zero', function () {
    // Uno zero verrebbe letto come una misura reale: nessuna corsa conclusa non
    // significa che sia durata zero secondi.
    $metrics = collect($this->getJson('/api/v1/state')->json('copilot.metrics'))->keyBy('key');

    expect($metrics['copilot.processing_seconds']['value'])->toBe('—')
        ->and($metrics['copilot.processing_seconds'])->not->toHaveKey('unit')
        ->and($metrics['copilot.ocr_confidence']['value'])->toBe('—');
});

test('communication metrics report failed, stuck and degraded generations', function () {
    config()->set('mvp.communications.generation_timeout_seconds', 300);

    Communication::factory()->draft()->create(['generation_status' => CommunicationGenerationStatus::Failed]);
    Communication::factory()->draft()->create([
        'generation_status' => CommunicationGenerationStatus::Processing,
        'workflow_started_at' => now()->subMinutes(10),
    ]);
    Communication::factory()->draft()->create([
        'generation_status' => CommunicationGenerationStatus::Processing,
        'workflow_started_at' => now()->subMinute(),
    ]);
    Communication::factory()->draft()->create(['cover_status' => CoverImageStatus::Failed]);

    $metrics = collect($this->getJson('/api/v1/state')->json('assistant.metrics'))->keyBy('key');

    expect($metrics['assistant.generation_failed']['value'])->toBe(1)
        ->and($metrics['assistant.generation_stuck']['value'])->toBe(1)
        ->and($metrics['assistant.covers_failed']['value'])->toBe(1);
});

test('the ready documents metric counts validated sub-documents without the quarantined ones', function () {
    SubDocument::factory()->create(['review_status' => ReviewStatus::AutoValidated]);
    SubDocument::factory()->create(['review_status' => ReviewStatus::ManuallyValidated]);
    SubDocument::factory()->create(['review_status' => ReviewStatus::Quarantined]);
    SubDocument::factory()->create(['review_status' => ReviewStatus::NeedsReview]);

    $metrics = collect($this->getJson('/api/v1/state')->json('copilot.metrics'))->keyBy('key');

    expect($metrics['copilot.validated']['value'])->toBe(2)
        ->and($metrics['copilot.quarantined']['value'])->toBe(1)
        ->and($metrics['copilot.needs_review']['value'])->toBe(1);
});

test('the document index only returns sub-documents of the caller tenant', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);

    $mine = SubDocument::factory()->create();
    ExtractedData::factory()->create(['sub_document_id' => $mine->id, 'company_name' => 'Azienda Mia']);

    $foreign = SubDocument::factory()->create();
    $foreign->originalDocument->update(['tenant_id' => 'another-tenant']);
    ExtractedData::factory()->create(['sub_document_id' => $foreign->id, 'company_name' => 'Azienda Altrui']);

    $response = $this->withHeaders([
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-a',
        'X-Mvp-User-Email' => 'operator-a@example.test',
        'X-Mvp-Tenant-Id' => 'mvp-local-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])->getJson('/api/v1/documents');

    $response->assertOk()->assertJsonPath('total', 1);
    expect($response->json('items.0.companyName'))->toBe('Azienda Mia');
});

test('the document index filters by employee name and company', function () {
    $target = SubDocument::factory()->create();
    ExtractedData::factory()->create([
        'sub_document_id' => $target->id,
        'employee_last_name' => 'Rossini',
        'company_name' => 'Acme',
    ]);

    $other = SubDocument::factory()->create();
    ExtractedData::factory()->create([
        'sub_document_id' => $other->id,
        'employee_last_name' => 'Bianchi',
        'company_name' => 'Globex',
    ]);

    $this->getJson('/api/v1/documents?search=Rossini')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.id', 'sub-'.$target->id);

    $this->getJson('/api/v1/documents?search=Globex')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.id', 'sub-'.$other->id);
});

test('the document index filters by send status', function () {
    $sent = SubDocument::factory()->sent()->create();
    ExtractedData::factory()->create(['sub_document_id' => $sent->id]);

    $pending = SubDocument::factory()->pending()->create();
    ExtractedData::factory()->create(['sub_document_id' => $pending->id]);

    $this->getJson('/api/v1/documents?sendStatus=sent')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.id', 'sub-'.$sent->id);
});

test('the document index filters by confidence threshold in both directions', function () {
    $low = SubDocument::factory()->create();
    ExtractedData::factory()->create(['sub_document_id' => $low->id, 'confidence_score' => 40]);

    $high = SubDocument::factory()->create();
    ExtractedData::factory()->create(['sub_document_id' => $high->id, 'confidence_score' => 95]);

    $this->getJson('/api/v1/documents?confidenceThreshold=80&confidenceCriterion=below')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.id', 'sub-'.$low->id);

    $this->getJson('/api/v1/documents?confidenceThreshold=80&confidenceCriterion=above')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.id', 'sub-'.$high->id);
});

test('the document index filters by month and year of the document date', function () {
    $target = SubDocument::factory()->create();
    ExtractedData::factory()->create(['sub_document_id' => $target->id, 'document_date' => '2026-03-15']);

    $other = SubDocument::factory()->create();
    ExtractedData::factory()->create(['sub_document_id' => $other->id, 'document_date' => '2025-11-02']);

    $this->getJson('/api/v1/documents?year=2026')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.id', 'sub-'.$target->id);

    $this->getJson('/api/v1/documents?month=11')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('items.0.id', 'sub-'.$other->id);
});

test('the document index rejects out-of-range filters', function () {
    $this->withHeader('Accept', 'application/json')
        ->getJson('/api/v1/documents?month=13')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    $this->withHeader('Accept', 'application/json')
        ->getJson('/api/v1/documents?confidenceThreshold=500')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

test('downloading the send message marks the sub-document as sent', function () {
    $subDocument = SubDocument::factory()->pending()->create();
    ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    expect($subDocument->send_status)->toBe(SendStatus::Pending);

    $this->get("/api/v1/documents/{$subDocument->id}/send-export")->assertOk();

    expect($subDocument->refresh()->send_status)->toBe(SendStatus::Sent)
        ->and(AuditEvent::query()->where('event_type', 'mvp-sub-document-send-exported')->count())->toBe(1);
});

test('previewing the send message does not mark the sub-document as sent', function () {
    // Guardare non e' recapitare: solo il download vale come invio.
    $subDocument = SubDocument::factory()->pending()->create();
    ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    $this->get("/api/v1/documents/{$subDocument->id}/send-preview")->assertOk();

    expect($subDocument->refresh()->send_status)->toBe(SendStatus::Pending);
});

test('a second download does not duplicate the send transition', function () {
    $subDocument = SubDocument::factory()->pending()->create();
    ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    $this->get("/api/v1/documents/{$subDocument->id}/send-export")->assertOk();
    $this->get("/api/v1/documents/{$subDocument->id}/send-export")->assertOk();

    expect($subDocument->refresh()->send_status)->toBe(SendStatus::Sent)
        ->and(AuditEvent::query()->where('event_type', 'mvp-sub-document-send-exported')->count())->toBe(1);
});

test('send-preview and send-export return 404 for a nonexistent sub-document', function () {
    $this->withHeader('Accept', 'application/json')
        ->get('/api/v1/documents/999999/send-preview')
        ->assertNotFound();

    $this->withHeader('Accept', 'application/json')
        ->get('/api/v1/documents/999999/send-export')
        ->assertNotFound();
});

test('send-preview and send-export reject cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $subDocument = SubDocument::factory()->create();

    $headers = [
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ];

    $this->withHeaders($headers)->get("/api/v1/documents/{$subDocument->id}/send-preview")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    $this->withHeaders($headers)->get("/api/v1/documents/{$subDocument->id}/send-export")
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

test('a discarded communication rejects a manual cover image upload', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->discarded()->coverReady()->create();

    $this->withHeader('Accept', 'application/json')
        ->post("/api/v1/communications/{$communication->id}/cover-image", [
            'image' => UploadedFile::fake()->image('manual-cover.png', 1280, 720),
        ])
        ->assertUnprocessable();
});

test('operator can download the cover image of a communication', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->draft()->coverReady()->create();
    Storage::disk('s3')->put($communication->cover_image_path, 'contenuto-copertina');

    $response = $this->get("/api/v1/communications/{$communication->id}/cover-image");

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png');

    expect($response->getContent())->toBe('contenuto-copertina');
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

test('a discarded communication rejects a manual cover image removal', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->discarded()->coverReady()->create();
    Storage::disk('s3')->put($communication->cover_image_path, 'copertina');

    $this->withHeader('Accept', 'application/json')
        ->deleteJson("/api/v1/communications/{$communication->id}/cover-image")
        ->assertUnprocessable();
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

test('operator can preview the final laid-out communication PDF', function () {
    $communication = Communication::factory()->draft()->create();

    $response = $this->get("/api/v1/communications/{$communication->id}/preview");

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline');

    mvpAssertWellFormedPdf($response->getContent());
});

test('operator can export the communication as a downloadable PDF', function () {
    $communication = Communication::factory()->draft()->create();

    $response = $this->get("/api/v1/communications/{$communication->id}/export");

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('.pdf');

    mvpAssertWellFormedPdf($response->getContent());
});

test('preview embeds the cover image when one is ready', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->draft()->coverReady()->create();
    Storage::disk('s3')->put($communication->cover_image_path, 'contenuto-copertina');

    $response = $this->get("/api/v1/communications/{$communication->id}/preview");

    $response->assertOk();
    mvpAssertWellFormedPdf($response->getContent());
});

test('the rendered PDF is materialized once and reused on the next hit', function () {
    Storage::fake('s3');
    config(['mvp.communications.pdf_disk' => 's3']);

    $communication = Communication::factory()->draft()->create();

    $this->get("/api/v1/communications/{$communication->id}/preview")->assertOk();

    $stored = Storage::disk('s3')->allFiles("communications/exports/{$communication->id}");
    expect($stored)->toHaveCount(1);

    // Sostituire la copia materializzata e ritrovarla nella risposta e' l'unico
    // modo per provare che dompdf non e' stato interpellato una seconda volta.
    Storage::disk('s3')->put($stored[0], 'copia-materializzata');

    $second = $this->get("/api/v1/communications/{$communication->id}/preview");

    $second->assertOk();
    expect($second->getContent())->toBe('copia-materializzata');
});

test('the export survives an unavailable PDF cache disk', function () {
    // La cache e' un'ottimizzazione: un disco non configurato deve far
    // rigenerare il PDF, non far fallire il download.
    config(['mvp.communications.pdf_disk' => 'disco-inesistente']);

    $communication = Communication::factory()->draft()->create();

    $response = $this->get("/api/v1/communications/{$communication->id}/export");

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    mvpAssertWellFormedPdf($response->getContent());
});

test('preview answers 304 when the client already holds the current PDF', function () {
    Storage::fake('s3');
    config(['mvp.communications.pdf_disk' => 's3']);

    $communication = Communication::factory()->draft()->create();

    $first = $this->get("/api/v1/communications/{$communication->id}/preview");
    $first->assertOk();

    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeEmpty();

    $this->withHeader('If-None-Match', $etag)
        ->get("/api/v1/communications/{$communication->id}/preview")
        ->assertStatus(304);
});

test('a new cover invalidates the materialized PDF', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3', 'mvp.communications.pdf_disk' => 's3']);

    $communication = Communication::factory()->draft()->create();

    $before = $this->get("/api/v1/communications/{$communication->id}/preview");
    $before->assertOk();

    $communication->update([
        'cover_image_path' => "communications/covers/{$communication->id}/nuova.png",
        'cover_image_mime' => 'image/png',
        'cover_status' => CoverImageStatus::Ready,
    ]);
    Storage::disk('s3')->put("communications/covers/{$communication->id}/nuova.png", 'contenuto-copertina');

    $after = $this->get("/api/v1/communications/{$communication->id}/preview");

    $after->assertOk();
    expect($after->headers->get('ETag'))->not->toBe($before->headers->get('ETag'));
    expect(Storage::disk('s3')->allFiles("communications/exports/{$communication->id}"))->toHaveCount(2);
});

test('operator can request a regeneration of a completed communication draft', function () {
    mvpMockCommunicationWorkflowRegenerate($this);

    $communication = Communication::factory()->draft()->coverReady()->create();

    $this->postJson("/api/v1/communications/{$communication->id}/regenerate")
        ->assertAccepted()
        ->assertJsonPath('message', 'Rigenerazione avviata.')
        ->assertJsonStructure(['message', 'communicationId', 'streamUrl']);
});

test('a failed communication draft can also be regenerated', function () {
    mvpMockCommunicationWorkflowRegenerate($this);

    $communication = Communication::factory()->draft()->create([
        'generation_status' => CommunicationGenerationStatus::Failed,
        'error_message' => 'Generazione non disponibile.',
    ]);

    $this->postJson("/api/v1/communications/{$communication->id}/regenerate")
        ->assertAccepted();
});

test('regenerating a communication still in progress is rejected', function () {
    $communication = Communication::factory()->processing()->create();

    $this->withHeader('Accept', 'application/json')
        ->postJson("/api/v1/communications/{$communication->id}/regenerate")
        ->assertStatus(409);
});

test('a discarded communication cannot be regenerated', function () {
    $communication = Communication::factory()->discarded()->create();

    $this->withHeader('Accept', 'application/json')
        ->postJson("/api/v1/communications/{$communication->id}/regenerate")
        ->assertUnprocessable();
});

test('a communication already saved to history can still be regenerated', function () {
    mvpMockCommunicationWorkflowRegenerate($this);

    $communication = Communication::factory()->approved()->coverReady()->create();

    $this->postJson("/api/v1/communications/{$communication->id}/regenerate")
        ->assertAccepted();
});

test('regenerate endpoint rejects cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $communication = Communication::factory()->draft()->coverReady()->create();

    $this->withHeaders([
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])->postJson("/api/v1/communications/{$communication->id}/regenerate")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

test('preview and export reject cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $communication = Communication::factory()->draft()->create();

    $headers = [
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ];

    $this->withHeaders($headers)->get("/api/v1/communications/{$communication->id}/preview")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    $this->withHeaders($headers)->get("/api/v1/communications/{$communication->id}/export")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

test('preview and export are blocked while generation is not completed', function () {
    $communication = Communication::factory()->processing()->create();

    $this->withHeader('Accept', 'application/json')
        ->get("/api/v1/communications/{$communication->id}/preview")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    $this->withHeader('Accept', 'application/json')
        ->get("/api/v1/communications/{$communication->id}/export")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

test('preview and export are blocked for a discarded communication', function () {
    $communication = Communication::factory()->discarded()->create();

    $this->withHeader('Accept', 'application/json')
        ->get("/api/v1/communications/{$communication->id}/preview")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    $this->withHeader('Accept', 'application/json')
        ->get("/api/v1/communications/{$communication->id}/export")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

test('preview and export return 404 for a nonexistent communication', function () {
    $this->withHeader('Accept', 'application/json')
        ->get('/api/v1/communications/999999/preview')
        ->assertNotFound();

    $this->withHeader('Accept', 'application/json')
        ->get('/api/v1/communications/999999/export')
        ->assertNotFound();
});

test('regenerating a communication replaces text and cover with a new variant', function () {
    config([
        'services.workflow.communications_state_machine_arn' => 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-communication-pipeline',
        'services.workflow.communications_task_queue_url' => 'http://localstack:4566/000000000000/mvp-communications',
    ]);
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->draft()->coverReady()->create([
        'generated_title' => 'Titolo vecchio',
        'generated_body' => 'Corpo vecchio',
    ]);
    $previousCoverPath = $communication->cover_image_path;
    Storage::disk('s3')->put($previousCoverPath, 'vecchia-copertina');

    $engine = Mockery::mock(WorkflowEnginePort::class);
    $engine->shouldReceive('startExecution')
        ->once()
        ->andReturn('arn:aws:states:eu-north-1:000000000000:execution:mvp-communication-pipeline:test');
    app()->instance(WorkflowEnginePort::class, $engine);

    app(StartCommunicationWorkflowUseCase::class)->regenerate($communication->id, null, null, null);

    $regenerated = $communication->fresh();

    expect($regenerated->generated_title)->toBeNull()
        ->and($regenerated->generated_body)->toBeNull()
        ->and($regenerated->generation_status)->toBe(CommunicationGenerationStatus::Processing)
        ->and($regenerated->cover_image_path)->toBeNull()
        ->and($regenerated->cover_status)->toBe(CoverImageStatus::Pending)
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-regeneration-requested')->count())->toBe(1);

    Storage::disk('s3')->assertMissing($previousCoverPath);

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('generateCommunication')
            ->once()
            ->andReturn(['title' => 'Titolo nuovo', 'body' => 'Corpo nuovo', 'image_prompt' => 'New visual direction']);

        $mock->shouldReceive('generateCommunicationImageWithMeta')
            ->once()
            ->andReturn([
                'bytes' => 'nuova-immagine',
                'mime' => 'image/png',
                'warning' => null,
                'reason' => null,
            ]);
    });

    mvpRunCommunicationTask($regenerated, 'communication.generate_text');
    mvpRunCommunicationTask($regenerated, 'communication.generate_cover');
    mvpRunCommunicationTask($regenerated, 'communication.finalize');

    $regenerated->refresh();

    expect($regenerated->generated_title)->toBe('Titolo nuovo')
        ->and($regenerated->generated_body)->toBe('Corpo nuovo')
        ->and($regenerated->cover_image_path)->not->toBe($previousCoverPath)
        ->and($regenerated->generation_status)->toBe(CommunicationGenerationStatus::Completed);

    Storage::disk('s3')->assertExists($regenerated->cover_image_path);
});

test('operator can save a communication draft to history (UC-9)', function () {
    $communication = Communication::factory()->draft()->coverReady()->create();

    $this->postJson("/api/v1/communications/{$communication->id}/save")
        ->assertOk()
        ->assertJsonPath('message', 'Bozza salvata nello storico.')
        ->assertJsonPath('communication.id', $communication->id)
        ->assertJsonPath('communication.status', 'Approvata');

    expect($communication->fresh()->status)->toBe(CommunicationStatus::Approved)
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-saved')->count())->toBe(1);
});

test('a saved communication remains visible in the history returned to the operator', function () {
    $communication = Communication::factory()->draft()->coverReady()->create();

    $this->postJson("/api/v1/communications/{$communication->id}/save")->assertOk();

    $response = $this->getJson('/api/v1/state')->assertOk();

    $historyIds = collect($response->json('assistant.history'))->pluck('id');

    expect($historyIds)->toContain($communication->id);
});

test('a communication already saved to history cannot be saved again', function () {
    $communication = Communication::factory()->approved()->create();

    $this->withHeader('Accept', 'application/json')
        ->postJson("/api/v1/communications/{$communication->id}/save")
        ->assertUnprocessable();
});

test('a discarded communication cannot be saved to history', function () {
    $communication = Communication::factory()->discarded()->create();

    $this->withHeader('Accept', 'application/json')
        ->postJson("/api/v1/communications/{$communication->id}/save")
        ->assertUnprocessable();
});

test('save endpoint rejects cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $communication = Communication::factory()->draft()->coverReady()->create();

    $this->withHeaders([
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])->postJson("/api/v1/communications/{$communication->id}/save")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

test('a saved communication can still be edited', function () {
    $communication = Communication::factory()->approved()->create();

    $this->putJson("/api/v1/communications/{$communication->id}", [
        'title' => 'Nuovo titolo',
        'body' => 'Nuovo corpo',
    ])
        ->assertOk()
        ->assertJsonPath('communication.title', 'Nuovo titolo');
});

test('operator can discard a communication draft', function () {
    $communication = Communication::factory()->draft()->coverReady()->create();

    $this->postJson("/api/v1/communications/{$communication->id}/discard")
        ->assertOk()
        ->assertJsonPath('message', 'Bozza scartata.')
        ->assertJsonPath('communication.id', $communication->id)
        ->assertJsonPath('communication.status', 'Scartata');

    expect($communication->fresh()->status)->toBe(CommunicationStatus::Discarded)
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-discarded')->count())->toBe(1);
});

test('a discarded communication no longer appears in the history returned to the operator', function () {
    $communication = Communication::factory()->approved()->coverReady()->create();

    $this->postJson("/api/v1/communications/{$communication->id}/discard")->assertOk();

    $response = $this->getJson('/api/v1/state')->assertOk();

    $historyIds = collect($response->json('assistant.history'))->pluck('id');

    expect($historyIds)->not->toContain($communication->id);
});

test('a draft not yet saved does not appear in the history until UC-9 is completed', function () {
    Communication::factory()->draft()->create();

    $response = $this->getJson('/api/v1/state')->assertOk();

    expect($response->json('assistant.history'))->toBeEmpty();
});

test('an already discarded communication cannot be discarded again', function () {
    $communication = Communication::factory()->discarded()->create();

    $this->withHeader('Accept', 'application/json')
        ->postJson("/api/v1/communications/{$communication->id}/discard")
        ->assertUnprocessable();
});

test('discard endpoint rejects cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $communication = Communication::factory()->draft()->coverReady()->create();

    $this->withHeaders([
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])->postJson("/api/v1/communications/{$communication->id}/discard")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

test('operator can permanently delete a communication from history', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->draft()->coverReady()->create();
    $coverPath = $communication->cover_image_path;
    Storage::disk('s3')->put($coverPath, 'copertina');

    $this->deleteJson("/api/v1/communications/{$communication->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Generazione eliminata dallo storico.')
        ->assertJsonStructure(['message', 'state']);

    expect(Communication::query()->find($communication->id))->toBeNull()
        ->and(AuditEvent::query()->where('event_type', 'mvp-communication-deleted')->count())->toBe(1);

    Storage::disk('s3')->assertMissing($coverPath);
});

test('delete communication endpoint rejects cross tenant access', function () {
    config(['mvp.identity.mode' => 'trusted_headers']);
    $communication = Communication::factory()->draft()->coverReady()->create();

    $this->withHeaders([
        'Accept' => 'application/json',
        'X-Mvp-User-Id' => 'operator-b',
        'X-Mvp-User-Email' => 'operator-b@example.test',
        'X-Mvp-Tenant-Id' => 'another-tenant',
        'X-Mvp-Roles' => 'mvp-operator',
    ])->deleteJson("/api/v1/communications/{$communication->id}")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');

    expect(Communication::query()->find($communication->id))->not->toBeNull();
});
