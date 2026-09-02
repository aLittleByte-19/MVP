<?php

use App\Models\Communication;
use App\Models\ExtractedData;
use App\Models\PromptConfiguration;
use App\Models\SubDocument;
use App\Mvp\Documents\Domain\Enums\ReviewStatus;
use App\Mvp\Workflow\Ports\Outbound\WorkflowEnginePort;
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
    config([
        'services.workflow.communications_state_machine_arn' => config('services.workflow.communications_state_machine_arn') ?: 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-communication-pipeline',
        'services.workflow.communications_task_queue_url' => config('services.workflow.communications_task_queue_url') ?: 'http://localstack:4566/000000000000/mvp-communications',
    ]);

    $engine = Mockery::mock(WorkflowEnginePort::class);
    $engine->shouldReceive('startExecution')
        ->once()
        ->andReturn('arn:aws:states:eu-north-1:000000000000:execution:fake:mvp-comm-test');
    app()->instance(WorkflowEnginePort::class, $engine);

    $response = $this->postJson('/api/v1/communications', [
        'prompt' => 'Comunica i nuovi orari di apertura degli uffici',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ])->assertAccepted();

    OpenApiSpec::assertResponseMatchesContract($response->json(), '/api/v1/communications', 'post', '202');
});

test('POST /api/v1/communications con payload invalido rispetta il contratto per il 422', function () {
    $response = $this->postJson('/api/v1/communications', [])->assertUnprocessable();

    OpenApiSpec::assertResponseMatchesContract($response->json(), '/api/v1/communications', 'post', '422');

    // requestId e correlationId sono valorizzati dal middleware di correlazione
    expect($response->json('error.requestId'))->toBeString()
        ->and($response->json('error.correlationId'))->toBeString();
});

test('POST /api/v1/prompt-configurations rispetta il contratto OpenAPI', function () {
    $response = $this->postJson('/api/v1/prompt-configurations', [
        'name' => 'Comunicazione ferie',
        'prompt' => 'Avvisa il personale delle nuove ferie disponibili da prenotare.',
        'tone' => 'Chiaro e diretto',
        'style' => 'Testo informativo',
    ])->assertCreated();

    OpenApiSpec::assertResponseMatchesContract($response->json(), '/api/v1/prompt-configurations', 'post', '201');
});

test('POST /api/v1/prompt-configurations con payload invalido rispetta il contratto per il 422', function () {
    $response = $this->postJson('/api/v1/prompt-configurations', [])->assertUnprocessable();

    OpenApiSpec::assertResponseMatchesContract($response->json(), '/api/v1/prompt-configurations', 'post', '422');
});

test('DELETE /api/v1/prompt-configurations/{promptConfiguration} rispetta il contratto OpenAPI', function () {
    $configuration = PromptConfiguration::factory()->create();

    $response = $this->deleteJson("/api/v1/prompt-configurations/{$configuration->id}")->assertOk();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/prompt-configurations/{promptConfiguration}',
        'delete',
        '200',
    );
});

test('POST /api/v1/communications/{communication}/cover-image rispetta il contratto OpenAPI', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->draft()->create();

    $response = $this->withHeader('Accept', 'application/json')
        ->post("/api/v1/communications/{$communication->id}/cover-image", [
            'image' => UploadedFile::fake()->image('contract-cover.png', 1280, 720),
        ])->assertOk();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/communications/{communication}/cover-image',
        'post',
        '200',
    );
});

test('DELETE /api/v1/communications/{communication}/cover-image rispetta il contratto OpenAPI', function () {
    Storage::fake('s3');
    config(['mvp.communications.cover_disk' => 's3']);

    $communication = Communication::factory()->draft()->coverReady()->create();

    $response = $this->deleteJson("/api/v1/communications/{$communication->id}/cover-image")->assertOk();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/communications/{communication}/cover-image',
        'delete',
        '200',
    );
});

test('POST /api/v1/communications/{communication}/favorite rispetta il contratto OpenAPI', function () {
    $communication = Communication::factory()->draft()->create(['is_favorite' => false]);

    $response = $this->postJson("/api/v1/communications/{$communication->id}/favorite")->assertOk();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/communications/{communication}/favorite',
        'post',
        '200',
    );
});

test('POST /api/v1/communications/{communication}/favorite gia preferita rispetta il contratto per il 422', function () {
    $communication = Communication::factory()->draft()->favorite()->create();

    $response = $this->postJson("/api/v1/communications/{$communication->id}/favorite")->assertUnprocessable();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/communications/{communication}/favorite',
        'post',
        '422',
    );
});

test('DELETE /api/v1/communications/{communication}/favorite rispetta il contratto OpenAPI', function () {
    $communication = Communication::factory()->draft()->favorite()->create();

    $response = $this->deleteJson("/api/v1/communications/{$communication->id}/favorite")->assertOk();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/communications/{communication}/favorite',
        'delete',
        '200',
    );
});

test('DELETE /api/v1/communications/{communication}/favorite non preferita rispetta il contratto per il 422', function () {
    $communication = Communication::factory()->draft()->create(['is_favorite' => false]);

    $response = $this->deleteJson("/api/v1/communications/{$communication->id}/favorite")->assertUnprocessable();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/communications/{communication}/favorite',
        'delete',
        '422',
    );
});

test('POST /api/v1/documents/ocr rispetta il contratto OpenAPI', function () {
    Storage::fake('s3');

    config([
        'services.workflow.state_machine_arn' => config('services.workflow.state_machine_arn') ?: 'arn:aws:states:eu-north-1:000000000000:stateMachine:mvp-document-pipeline',
        'services.workflow.task_queue_url' => config('services.workflow.task_queue_url') ?: 'http://localstack:4566/000000000000/mvp-documents',
    ]);

    $workflow = Mockery::mock(WorkflowEnginePort::class);
    $workflow->shouldReceive('startExecution')
        ->once()
        ->andReturn('arn:aws:states:eu-north-1:000000000000:execution:fake:mvp-doc-test');
    app()->instance(WorkflowEnginePort::class, $workflow);

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

test('GET /api/v1/documents/{subDocument}/send-export con allegato mancante rispetta il contratto per il 404', function () {
    Storage::fake('s3');
    $subDocument = SubDocument::factory()->pending()->confirmed()->create();
    ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    $response = $this->getJson("/api/v1/documents/{$subDocument->id}/send-export")->assertNotFound();

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/documents/{subDocument}/send-export',
        'get',
        '404',
    );
});

test('GET /api/v1/documents/{subDocument}/send-export senza revisione confermata rispetta il contratto per il 422', function () {
    $subDocument = SubDocument::factory()->pending()->create(['review_status' => ReviewStatus::AutoValidated]);
    ExtractedData::factory()->create(['sub_document_id' => $subDocument->id]);

    $response = $this->getJson("/api/v1/documents/{$subDocument->id}/send-export")->assertStatus(422);

    OpenApiSpec::assertResponseMatchesContract(
        $response->json(),
        '/api/v1/documents/{subDocument}/send-export',
        'get',
        '422',
    );
});
