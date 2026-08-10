<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesDocuments;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Http\Requests\ListDocumentsRequest;
use App\Http\Requests\UploadDocumentRequest;
use App\Models\OriginalDocument;
use App\Models\SubDocument;
use App\Mvp\Documents\Domain\Commands\UploadDocumentCommand;
use App\Mvp\Documents\Domain\Ports\Inbound\DeleteDocumentUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\ListDocumentsUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\StartDocumentWorkflowUseCase;
use App\Mvp\Documents\Domain\Ports\Inbound\UploadDocumentUseCase;
use App\Mvp\Documents\Enums\ProcessingStatus;
use App\Mvp\Support\MvpStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Adapter primario HTTP: traduce le richieste nei casi d'uso del dominio
 * Documents tramite le loro porte primarie. Nessuna regola di business qui —
 * quella vive in Domain/Application (vedi ADR 0010). `stream()` resta
 * un'eccezione dichiarata: e' solo presentazione (polling SSE + shaping via
 * MvpStateService, infrastruttura condivisa fuori perimetro), nessuna
 * decisione di dominio.
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

    public function stream(Request $request, OriginalDocument $originalDocument, MvpStateService $state): StreamedResponse
    {
        $actor = $this->actor($request);
        $this->authorizeOriginalDocument($originalDocument, $actor);

        return response()->stream(function () use ($originalDocument, $actor, $state): void {
            if (app()->runningUnitTests()) {
                return;
            }

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
                $freshDocument = OriginalDocument::query()
                    ->with(['subDocuments' => fn ($query) => $query
                        ->with(['originalDocument', 'extractedData'])
                        ->orderBy('id')])
                    ->find($originalDocument->id);

                if (! $freshDocument) {
                    $send('error', ['message' => 'Documento non trovato.']);

                    return;
                }

                foreach ($freshDocument->subDocuments as $subDocument) {
                    if (in_array($subDocument->id, $sentDocumentIds, true) || ! $subDocument->extractedData) {
                        continue;
                    }

                    $sentDocumentIds[] = $subDocument->id;
                    $send('document', $state->document($subDocument));
                }

                // Avanzamento a step per la barra di progressione della SPA: stato
                // del workflow + numero di sotto-documenti gia' estratti. Si emette
                // solo quando qualcosa cambia; altrimenti un heartbeat tiene viva la
                // connessione senza generare rumore.
                $signature = $freshDocument->processing_status->value.':'.count($sentDocumentIds);

                if ($signature !== $lastSignature) {
                    $send('progress', [
                        'status' => $freshDocument->processing_status->value,
                        'subDocuments' => count($sentDocumentIds),
                    ]);
                    $lastSignature = $signature;
                } else {
                    $heartbeat();
                }

                if ($freshDocument->processing_status === ProcessingStatus::Completed) {
                    $send('done', ['state' => $state->forActor($actor)]);

                    return;
                }

                if ($freshDocument->processing_status === ProcessingStatus::Failed) {
                    $send('error', ['message' => $freshDocument->error_message ?: 'Analisi documento non disponibile.']);

                    return;
                }

                if (time() - $startedAt >= $timeoutSeconds) {
                    $send('error', ['message' => 'Timeout elaborazione.']);

                    return;
                }

                if (app()->runningUnitTests()) {
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
