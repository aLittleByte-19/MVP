<?php

use App\Poc\Jobs\ProcessOriginalDocumentJob;
use App\Poc\Models\AuditEvent;
use App\Poc\Models\Communication;
use App\Poc\Models\ExtractedData;
use App\Poc\Models\OriginalDocument;
use App\Poc\Models\SubDocument;
use App\Poc\Services\BedrockService;
use App\Poc\Services\DocumentProcessingService;
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

    // Run the job manually: commits each sub-document individually as in production.
    (new ProcessOriginalDocumentJob($document))
        ->handle(app(DocumentProcessingService::class));

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

test('document processing fails when classifier returns no usable segments', function () {
    config([
        'filesystems.default' => 's3',
    ]);

    Queue::fake();
    Storage::fake('s3');

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('splitDocument')
            ->once()
            ->andReturn([]);

        $mock->shouldNotReceive('extractFields');
    });

    $uploadResponse = $this->postJson('/api/v1/documents/ocr', ['document' => pocPdfUpload()])
        ->assertStatus(202)
        ->assertJsonStructure(['streamUrl']);

    $document = OriginalDocument::query()->first();

    expect(fn () => (new ProcessOriginalDocumentJob($document))
        ->handle(app(DocumentProcessingService::class)))
        ->toThrow(RuntimeException::class, 'segmenti elaborabili');

    expect(SubDocument::query()->count())->toBe(0)
        ->and($document->refresh()->processing_status->value)->toBe('failed')
        ->and($document->error_message)->toBe('Analisi documento non disponibile. Verifica configurazione e permessi Bedrock.');

    $this->get($uploadResponse->json('streamUrl'))->assertOk();
});

test('document processing clamps model page ranges to the uploaded pdf page count', function () {
    config([
        'filesystems.default' => 's3',
    ]);

    Queue::fake();
    Storage::fake('s3');

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
    (new ProcessOriginalDocumentJob($document))
        ->handle(app(DocumentProcessingService::class));

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
    expect(fn () => (new ProcessOriginalDocumentJob($document))
        ->handle(app(DocumentProcessingService::class)))
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
