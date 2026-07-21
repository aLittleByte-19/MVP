<?php

use App\Copilot\Ai\BedrockService;
use App\Copilot\Workflow\Services\DocumentWorkflowService;
use App\Models\Copilot\Communication;
use App\Models\Copilot\ExtractedData;
use App\Models\Copilot\OriginalDocument;
use App\Models\Copilot\SubDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Tests\Support\OpenApiSpec;

function contractPdfUpload(): UploadedFile
{
    $pdf = new Fpdi;
    $pdf->AddPage();
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(0, 10, 'Documento contract test');

    return UploadedFile::fake()->createWithContent('contract.pdf', $pdf->Output('S'));
}

test('GET /api/v1/state rispetta il contratto OpenAPI', function () {
    $subDocument = SubDocument::factory()->create();
    ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    $response = $this->getJson('/api/v1/state')->assertOk();

    OpenApiSpec::assertResponseMatchesContract($response->json(), '/api/v1/state', 'get', '200');
});

test('GET /api/v1/state senza identita valida rispetta il contratto per il 401', function () {
    config(['mvp.identity.mode' => 'trusted-headers']);

    $response = $this->getJson('/api/v1/state')->assertUnauthorized();

    OpenApiSpec::assertResponseMatchesContract($response->json(), '/api/v1/state', 'get', '401');
});

test('GET /api/v1/state senza ruolo abilitato rispetta il contratto per il 403', function () {
    config(['mvp.identity.local.roles' => ['ruolo-non-abilitato']]);

    $response = $this->getJson('/api/v1/state')->assertForbidden();

    OpenApiSpec::assertResponseMatchesContract($response->json(), '/api/v1/state', 'get', '403');
});

test('POST /api/v1/communications rispetta il contratto OpenAPI', function () {
    $this->mock(BedrockService::class, function ($mock) {
        $mock->shouldReceive('generateCommunication')
            ->once()
            ->andReturn(['title' => 'Aggiornamento orari', 'body' => 'Testo della comunicazione generata.']);
    });

    $response = $this->postJson('/api/v1/communications', [
        'prompt' => 'Comunica i nuovi orari di apertura degli uffici',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ])->assertCreated();

    OpenApiSpec::assertResponseMatchesContract($response->json(), '/api/v1/communications', 'post', '201');
});

test('POST /api/v1/communications con payload invalido rispetta il contratto per il 422', function () {
    $response = $this->postJson('/api/v1/communications', [])->assertUnprocessable();

    OpenApiSpec::assertResponseMatchesContract($response->json(), '/api/v1/communications', 'post', '422');

    // requestId e correlationId sono valorizzati dal middleware di correlazione
    expect($response->json('error.requestId'))->toBeString()
        ->and($response->json('error.correlationId'))->toBeString();
});

test('POST /api/v1/documents/ocr rispetta il contratto OpenAPI', function () {
    Storage::fake('s3');

    $workflow = Mockery::mock(DocumentWorkflowService::class);
    $workflow->shouldReceive('start')
        ->once()
        ->andReturnUsing(fn (OriginalDocument $document) => $document);
    app()->instance(DocumentWorkflowService::class, $workflow);

    $response = $this->postJson('/api/v1/documents/ocr', ['document' => contractPdfUpload()])
        ->assertStatus(202);

    OpenApiSpec::assertResponseMatchesContract($response->json(), '/api/v1/documents/ocr', 'post', '202');
});

test('POST /api/v1/documents/ocr con file invalido rispetta il contratto per il 422', function () {
    Storage::fake('s3');

    $response = $this->postJson('/api/v1/documents/ocr', [
        'document' => UploadedFile::fake()->createWithContent('fake.pdf', 'not a pdf'),
    ])->assertUnprocessable();

    OpenApiSpec::assertResponseMatchesContract($response->json(), '/api/v1/documents/ocr', 'post', '422');
});

test('PUT /api/v1/documents/{subDocument}/extracted-data rispetta il contratto OpenAPI', function () {
    $subDocument = SubDocument::factory()->create();
    ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    $response = $this->putJson("/api/v1/documents/{$subDocument->id}/extracted-data", [
        'employeeFirstName' => 'Maria',
        'markAsValidated' => true,
    ])->assertOk();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/documents/{subDocument}/extracted-data',
        'put',
        '200',
    );
});

test('POST /api/v1/documents/{subDocument}/review rispetta il contratto OpenAPI', function () {
    $subDocument = SubDocument::factory()->create();
    ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    $response = $this->postJson("/api/v1/documents/{$subDocument->id}/review")->assertOk();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/documents/{subDocument}/review',
        'post',
        '200',
    );
});

test('DELETE /api/v1/documents/{subDocument} rispetta il contratto OpenAPI', function () {
    Storage::fake('s3');

    $subDocument = SubDocument::factory()->create();

    $response = $this->deleteJson("/api/v1/documents/{$subDocument->id}")->assertOk();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/documents/{subDocument}',
        'delete',
        '200',
    );
});

test('POST /api/v1/communications/{communication}/rating rispetta il contratto OpenAPI', function () {
    $communication = Communication::factory()->draft()->create();

    $response = $this->postJson("/api/v1/communications/{$communication->id}/rating", [
        'rating' => 5,
        'comment' => 'Ottima bozza.',
    ])->assertOk();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/communications/{communication}/rating',
        'post',
        '200',
    );
});

test('POST /api/v1/communications/{communication}/rating con payload invalido rispetta il contratto per il 422', function () {
    $communication = Communication::factory()->draft()->create();

    $response = $this->postJson("/api/v1/communications/{$communication->id}/rating", [
        'rating' => 9,
        'comment' => str_repeat('x', 1001),
    ])->assertUnprocessable();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/communications/{communication}/rating',
        'post',
        '422',
    );
});
