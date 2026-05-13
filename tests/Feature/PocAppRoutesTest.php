<?php

use App\Poc\Models\Communication;
use App\Poc\Models\ExtractedData;
use App\Poc\Models\OriginalDocument;
use App\Poc\Models\SubDocument;
use App\Poc\Services\BedrockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function pocPdfUpload(string $filename = 'cedolino.pdf'): UploadedFile
{
    $pdf = new \FPDF;
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
    ]);

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

    // Manually trigger the job to simulate queue processing in the test environment
    (new \App\Poc\Jobs\ProcessOriginalDocumentJob($document))
        ->handle(app(\App\Poc\Services\DocumentProcessingService::class));

    // The stream only replays progress.
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
