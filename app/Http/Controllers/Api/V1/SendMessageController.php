<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesDocuments;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Http\Requests\UpdateSendMessageRequest;
use App\Models\SubDocument;
use App\Mvp\Documents\Domain\Exceptions\SendMessageAttachmentUnavailableException;
use App\Mvp\Documents\Domain\Ports\Inbound\SendMessageUseCase;
use App\Mvp\Support\MvpStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Adapter primario HTTP: messaggio di invio precompilato. Traduce le
 * richieste nel caso d'uso tramite la sua porta primaria (vedi ADR 0010).
 */
class SendMessageController
{
    use AuthorizesDocuments, ResolvesActor;

    public function sendPreview(Request $request, SubDocument $subDocument, SendMessageUseCase $sendMessage): Response|JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeSubDocument($subDocument, $actor);

        try {
            $rendered = $sendMessage->preview($subDocument->id, $actor);
        } catch (SendMessageAttachmentUnavailableException $exception) {
            return $this->attachmentUnavailable($exception);
        }

        return response($rendered->pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
        ]);
    }

    public function sendExport(Request $request, SubDocument $subDocument, SendMessageUseCase $sendMessage): Response|JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeSubDocument($subDocument, $actor);

        try {
            $rendered = $sendMessage->export($subDocument->id, $actor);
        } catch (SendMessageAttachmentUnavailableException $exception) {
            return $this->attachmentUnavailable($exception);
        }

        return response($rendered->pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $rendered->filename).'"',
        ]);
    }

    public function updateSendMessage(
        UpdateSendMessageRequest $request,
        SubDocument $subDocument,
        SendMessageUseCase $sendMessage,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->authorizeSubDocument($subDocument, $actor);

        $validated = $request->validated();
        $sendMessage->updateOverrides($subDocument->id, $validated, $actor);

        return response()->json([
            'message' => 'Messaggio di invio aggiornato.',
            'document' => $state->document($subDocument->fresh()),
            'state' => $state->forActor($actor),
        ]);
    }

    private function attachmentUnavailable(SendMessageAttachmentUnavailableException $exception): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'attachment_unavailable',
                'message' => $exception->getMessage(),
            ],
        ], 404);
    }
}
