<?php

use App\Models\Communication;
use App\Models\ExtractedData;
use App\Models\OriginalDocument;
use App\Models\SubDocument;
use App\Services\BedrockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function pocPdfUpload(string $filename = 'cedolino.pdf'): UploadedFile
{
    $pdf = new FPDF;
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

test('filament console is public for the poc', function () {
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
    Storage::fake('local');

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

    $response = $this->postJson('/poc/api/documents/ocr', ['document' => pocPdfUpload()])
        ->assertCreated()
        ->assertJsonPath('documents.0.employee', 'Mario Rossi')
        ->assertJsonPath('documents.0.confidence', 86)
        ->assertJsonStructure(['documents' => [['previewUrl']]]);

    expect(OriginalDocument::query()->count())->toBe(1);
    expect(SubDocument::query()->count())->toBe(1);
    expect(ExtractedData::query()->first()->employee_first_name)->toBe('Mario');

    $this->get($response->json('documents.0.previewUrl'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
