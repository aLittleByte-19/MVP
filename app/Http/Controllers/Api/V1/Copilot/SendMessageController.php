<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Communications\Enums\SendStatus;
use App\Copilot\Documents\Services\SubDocumentSendMessageService;
use App\Copilot\Support\MvpStateService;
use App\Http\Controllers\Api\V1\Copilot\Concerns\AuthorizesDocuments;
use App\Http\Controllers\Api\V1\Copilot\Concerns\ResolvesActor;
use App\Http\Requests\Copilot\UpdateSendMessageRequest;
use App\Models\Copilot\SubDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Messaggio di invio precompilato: anteprima, esportazione PDF (che marca lo scaricamento) e correzione dei campi.
 */
class SendMessageController
{
    use AuthorizesDocuments, ResolvesActor;

    public function sendPreview(Request $request, SubDocument $subDocument, SubDocumentSendMessageService $messages): Response
    {
        if ($subDocument->originalDocument) {
            $this->authorizeOriginalDocument($subDocument->originalDocument, $this->actor($request));
        }

        return response($messages->renderPdf($subDocument), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
        ]);
    }

    public function sendExport(
        Request $request,
        SubDocument $subDocument,
        SubDocumentSendMessageService $messages,
        AuditLogger $audit,
    ): Response {
        $actor = $this->actor($request);

        if ($subDocument->originalDocument) {
            $this->authorizeOriginalDocument($subDocument->originalDocument, $actor);
        }

        $pdf = $messages->renderPdf($subDocument);

        // Il recapito avviene fuori dalla piattaforma: il download del PDF e'
        // l'ultimo evento osservabile, quindi e' quello che marca l'invio.
        // Transizione a senso unico: un secondo download non cambia lo stato.
        if ($subDocument->send_status === SendStatus::Pending) {
            $subDocument->update(['send_status' => SendStatus::Sent]);

            $audit->record(
                'mvp-sub-document-send-exported',
                $actor,
                'sub_document',
                (string) $subDocument->id,
                ['sendStatus' => SendStatus::Sent->value],
                $request,
            );
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $messages->filename($subDocument)).'"',
        ]);
    }

    public function updateSendMessage(
        UpdateSendMessageRequest $request,
        SubDocument $subDocument,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->authorizeSubDocument($subDocument, $actor);

        $validated = $request->validated();
        $subDocument->update([
            'send_recipient_override' => array_key_exists('recipient', $validated)
                ? $validated['recipient']
                : $subDocument->send_recipient_override,
            'send_subject_override' => array_key_exists('subject', $validated)
                ? $validated['subject']
                : $subDocument->send_subject_override,
            'send_body_override' => array_key_exists('body', $validated)
                ? $validated['body']
                : $subDocument->send_body_override,
        ]);

        $audit->record(
            'mvp-sub-document-send-message-corrected',
            $actor,
            'sub_document',
            (string) $subDocument->id,
            ['fields' => array_keys($validated)],
            $request,
        );

        return response()->json([
            'message' => 'Messaggio di invio aggiornato.',
            'document' => $state->document($subDocument->fresh()),
            'state' => $state->forActor($actor),
        ]);
    }
}
