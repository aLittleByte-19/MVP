<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesDocuments;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Http\Requests\ListDocumentsRequest;
use App\Http\Requests\UploadDocumentRequest;
use App\Models\OriginalDocument;
use App\Models\SubDocument;
use App\Mvp\Documents\Domain\Commands\UploadDocumentCommand;
use App\Mvp\Documents\Domain\Enums\ProcessingStatus;
use App\Mvp\Documents\Domain\Ports\Inbound\DeleteDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ListDocumentsUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\PollDocumentProgressUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\StartDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\UploadDocumentUseCase;
use App\Mvp\Support\MvpStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Adapter primario HTTP: traduce le richieste nei casi d'uso del dominio
 * Documents tramite le loro porte primarie. Nessuna regola di business qui —
 * quella vive in Domain/Application (vedi ADR 0010). `stream()` legge lo
 * stato tramite PollDocumentProgressUseCase (porta primaria): il controller
 * resta responsabile solo del protocollo SSE e dello shaping via
 * MvpStateService (infrastruttura condivisa fuori perimetro), ricaricando
 * dal modello Eloquent solo i sotto-documenti nuovi da quest'ultimo poll.
 */
class DocumentController
{
    use AuthorizesDocuments, ResolvesActor;

    public function index(
        ListDocumentsRequest $request,
        ListDocumentsUseCase $list,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $filters = $request->validated();

        $page = $list->list(
            $actor->tenantId,
            $filters,
            (int) ($filters['page'] ?? 1),
            (int) ($filters['perPage'] ?? 40),
        );

        // La ricerca/filtro passa dalla porta (sopra); la forma di
        // presentazione HTTP richiede le relazioni Eloquent caricate per
        // MvpStateService, che resta fuori dal perimetro esagonale (ADR 0010).
        $documentsById = SubDocument::query()
            ->with(['originalDocument', 'extractedData'])
            ->whereIn('id', $page->subDocumentIds)
            ->get()
            ->keyBy('id');

        $items = collect($page->subDocumentIds)
            ->map(fn (int $id) => $documentsById->get($id))
            ->filter()
            ->map(fn (SubDocument $document) => $state->document($document))
            ->values();

        return response()->json([
            'items' => $items->all(),
            'total' => $page->total,
            'page' => $page->page,
            'perPage' => $page->perPage,
        ]);
    }

    public function store(
        UploadDocumentRequest $request,
        UploadDocumentUseCase $upload,
        StartDocumentWorkflowUseCase $startWorkflow,
    ): JsonResponse {
        $validated = $request->validated();
        $actor = $this->actor($request);
        $file = $validated['document'];
        $correlationId = $request->attributes->get('correlation_id');
        $requestId = $request->attributes->get('request_id');

        $documentId = $upload->upload(new UploadDocumentCommand(
            absoluteSourcePath: $file->getRealPath(),
            originalFilename: $file->getClientOriginalName(),
            actor: $actor,
            manualDocumentType: $request->manualMetadata()['document_type'],
            manualCompanyName: $request->manualMetadata()['company_name'],
            manualReferenceMonth: $request->manualMetadata()['reference_month'],
            manualReferenceYear: $request->manualMetadata()['reference_year'],
            correlationId: $correlationId,
            requestId: $requestId,
        ));

        $startWorkflow->start($documentId, $correlationId, $requestId);

        return response()->json([
            'message' => 'Documento caricato. Workflow documentale avviato.',
            // URL relativo: la SPA e' servita in HTTPS dietro Traefik, che termina il
            // TLS e inoltra in HTTP. Un URL assoluto verrebbe generato con schema
            // "http://" e bloccato dal browser come mixed-content / CSP connect-src.
            'streamUrl' => route('api.v1.documents.stream', ['originalDocument' => $documentId], false),
        ], 202);
    }

    public function stream(Request $request, OriginalDocument $originalDocument, MvpStateService $state, PollDocumentProgressUseCase $poll): StreamedResponse
    {
        $actor = $this->actor($request);
        $this->authorizeOriginalDocument($originalDocument, $actor);
        $documentId = $originalDocument->id;

        return response()->stream(function () use ($documentId, $actor, $state, $poll): void {
            set_time_limit(0);

            $send = function (string $event, array $data): void {
                echo "event: {$event}\ndata: ".json_encode($data)."\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            // Commento SSE: ignorato dal browser ma mantiene viva la connessione
            // quando non ci sono novita' (evita chiusure da idle-timeout dei proxy).
            $heartbeat = function (): void {
                echo ": keepalive\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            };

            $sentDocumentIds = [];
            $startedAt = time();
            $timeoutSeconds = 300;
            $lastSignature = null;

            while (! connection_aborted()) {
                try {
                    $snapshot = $poll->poll($documentId);
                } catch (\Throwable) {
                    $send('error', ['message' => 'Documento non trovato.']);

                    return;
                }

                $newSubDocumentIds = array_values(array_diff($snapshot->extractedSubDocumentIds, $sentDocumentIds));

                if ($newSubDocumentIds !== []) {
                    $freshSubDocuments = SubDocument::query()
                        ->with(['originalDocument', 'extractedData'])
                        ->whereIn('id', $newSubDocumentIds)
                        ->orderBy('id')
                        ->get();

                    foreach ($freshSubDocuments as $subDocument) {
                        $sentDocumentIds[] = $subDocument->id;
                        $send('document', $state->document($subDocument));
                    }
                }

                // Avanzamento a step per la barra di progressione della SPA: stato
                // del workflow + numero di sotto-documenti gia' estratti. Si emette
                // solo quando qualcosa cambia; altrimenti un heartbeat tiene viva la
                // connessione senza generare rumore.
                $signature = $snapshot->processingStatus.':'.count($sentDocumentIds);

                if ($signature !== $lastSignature) {
                    $send('progress', [
                        'status' => $snapshot->processingStatus,
                        'subDocuments' => count($sentDocumentIds),
                    ]);
                    $lastSignature = $signature;
                } else {
                    $heartbeat();
                }

                if ($snapshot->processingStatus === ProcessingStatus::Completed->value) {
                    $send('done', ['state' => $state->forActor($actor)]);

                    return;
                }

                if ($snapshot->processingStatus === ProcessingStatus::Failed->value) {
                    $send('error', ['message' => $snapshot->errorMessage ?: 'Analisi documento non disponibile.']);

                    return;
                }

                if (time() - $startedAt >= $timeoutSeconds) {
                    $send('error', ['message' => 'Timeout elaborazione.']);

                    return;
                }

                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function destroy(Request $request, SubDocument $subDocument, DeleteDocumentUseCase $delete, MvpStateService $state): JsonResponse
    {
        $actor = $this->actor($request);

        if ($subDocument->originalDocument) {
            $this->authorizeOriginalDocument($subDocument->originalDocument, $actor);
        }

        $delete->delete($subDocument->id, $actor);

        return response()->json([
            'message' => 'Documento eliminato.',
            'state' => $state->forActor($actor),
        ]);
    }
}
