<?php

use App\Poc\Jobs\ProcessOriginalDocumentJob;
use App\Poc\Models\Communication;
use App\Poc\Models\ExtractedData;
use App\Poc\Models\OriginalDocument;
use App\Poc\Models\SubDocument;
use App\Poc\Services\BedrockService;
use App\Poc\Services\DocumentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;

uses(RefreshDatabase::class);

function pocPdfUpload(string $filename = 'cedolino.pdf'): UploadedFile
{
    $pdf = new Fpdi;
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Cedolino dimostrativo PoC');

    return UploadedFile::fake()->createWithContent($filename, $pdf->Output('S'));
}

test('root renders the poc application without authentication', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Overview')
        ->assertSee('AI Assistant')
        ->assertSee('Co-Pilot CdL')
        ->assertDontSee('Gestione credenziali');
});

test('legacy app and login paths do not require authentication', function () {
    $this->get('/app')
        ->assertRedirect('/');

    $this->get('/login')
        ->assertRedirect('/');
});

test('blade admin console is public for the poc', function () {
    $this->get('/admin')
        ->assertOk()
        ->assertSee('Amministrazione PoC')
        ->assertSee('Salva configurazione')
        ->assertDontSee('Sign in');

    $this->get('/admin/ai-assistant')
        ->assertNotFound();

    $this->get('/admin/login')
        ->assertNotFound();
});

test('poc api state is public for the local poc', function () {
    $this->getJson('/poc/api/state')
        ->assertOk()
        ->assertJsonStructure(['assistant', 'copilot']);
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

    $this->postJson('/poc/api/communications', [
        'prompt' => 'Comunicazione interna sulla nuova area documentale.',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ])
        ->assertCreated()
        ->assertJsonPath('communication.title', 'Titolo reale');

    expect(Communication::query()->count())->toBe(1);
    expect(Communication::query()->first()->generated_body)->toBe('Corpo reale');
});

test('document upload performs initial split and field extraction', function () {
    config([
        'filesystems.default' => 's3',
        'services.documents.classifier_driver' => 'bedrock',
        'services.documents.ocr_driver' => 'bedrock',
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

    $uploadResponse = $this->postJson('/poc/api/documents/ocr', ['document' => pocPdfUpload()])
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
    $this->get(route('poc.documents.preview', $subDocument))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('document upload uses local extraction when ocr driver is local', function () {
    config([
        'filesystems.default' => 's3',
        'services.documents.classifier_driver' => 'bedrock',
        'services.documents.ocr_driver' => 'local',
        'services.bedrock.poc_confidence_threshold' => 72,
    ]);

    Queue::fake();
    Storage::fake('s3');

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('splitDocument')
            ->once()
            ->andReturn([
                ['employee_name' => 'Mario Rossi', 'start_page' => 1, 'end_page' => 1],
            ]);

        $mock->shouldNotReceive('extractFields');
    });

    $uploadResponse = $this->postJson('/poc/api/documents/ocr', ['document' => pocPdfUpload()])
        ->assertStatus(202)
        ->assertJsonStructure(['streamUrl']);

    $document = OriginalDocument::query()->first();

    (new ProcessOriginalDocumentJob($document))
        ->handle(app(DocumentProcessingService::class));

    $streamResponse = $this->get($uploadResponse->json('streamUrl'))->assertOk();
    ob_start();
    $streamResponse->baseResponse->sendContent();
    ob_end_clean();

    expect(ExtractedData::query()->first()->confidence_score)->toBe(72);
});

test('document processing clamps model page ranges to the uploaded pdf page count', function () {
    config([
        'filesystems.default' => 's3',
        'services.documents.classifier_driver' => 'bedrock',
        'services.documents.ocr_driver' => 'local',
    ]);

    Queue::fake();
    Storage::fake('s3');

    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('splitDocument')
            ->once()
            ->andReturn([
                ['employee_name' => 'Mario Rossi', 'start_page' => 5, 'end_page' => 10],
            ]);

        $mock->shouldNotReceive('extractFields');
    });

    $this->postJson('/poc/api/documents/ocr', ['document' => pocPdfUpload()])
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
        'services.documents.classifier_driver' => 'bedrock',
        'services.documents.ocr_driver' => 'bedrock',
    ]);

    Queue::fake();
    Storage::fake('s3');

    $expectedMessage = 'Le credenziali AWS temporanee sono scadute. Aggiorna AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY e AWS_SESSION_TOKEN nel pannello admin.';

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

    $this->postJson('/poc/api/documents/ocr', ['document' => pocPdfUpload()])
        ->assertStatus(202);

    $document = OriginalDocument::query()->first();
    (new ProcessOriginalDocumentJob($document))
        ->handle(app(DocumentProcessingService::class));

    $subDocument = SubDocument::query()->first();
    $extractedData = ExtractedData::query()->first();

    expect(SubDocument::query()->count())->toBe(1)
        ->and($subDocument->error_message)->toBe($expectedMessage)
        ->and($extractedData->employee_first_name)->toBeNull()
        ->and($extractedData->confidence_score)->toBeNull()
        ->and($document->refresh()->processing_status->value)->toBe('completed')
        ->and($document->error_message)->toBeNull();

    $this->getJson('/poc/api/state')
        ->assertOk()
        ->assertJsonPath('copilot.documents.0.error', $expectedMessage)
        ->assertJsonPath('copilot.documents.0.previewLines.3', 'Errore estrazione: '.$expectedMessage);
});

test('assistant generated metric counts every stored communication', function () {
    Communication::factory()->draft()->create();
    Communication::factory()->discarded()->create();

    $this->getJson('/poc/api/state')
        ->assertOk()
        ->assertJsonPath('assistant.metrics.0.value', 2)
        ->assertJsonPath('assistant.metrics.1.value', 1);
});
