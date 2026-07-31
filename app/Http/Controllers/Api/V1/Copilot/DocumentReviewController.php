<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Documents\Enums\ReviewStatus;
use App\Copilot\Support\MvpStateService;
use App\Http\Controllers\Api\V1\Copilot\Concerns\AuthorizesDocuments;
use App\Http\Controllers\Api\V1\Copilot\Concerns\ResolvesActor;
use App\Http\Requests\Copilot\UpdateExtractedDataRequest;
use App\Models\Copilot\ExtractedData;
use App\Models\Copilot\SubDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Revisione human-in-the-loop: correzione dei campi estratti e validazione manuale.
 */
class DocumentReviewController
{
    use AuthorizesDocuments, ResolvesActor;

    public function updateExtractedData(
        UpdateExtractedDataRequest $request,
        SubDocument $subDocument,
        AuditLogger $audit,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->authorizeSubDocument($subDocument, $actor);

        $validated = $request->validated();
        $existing = $subDocument->extractedData;
        $updates = $this->extractedDataUpdates($validated);

        if ($updates !== [] || ! $existing) {
            ExtractedData::updateOrCreate(
                ['sub_document_id' => $subDocument->id],
                $updates,
            );
        }

        $reviewStatus = ($validated['markAsValidated'] ?? false)
            ? ReviewStatus::ManuallyValidated
            : ReviewStatus::NeedsReview;
        $subDocument->update([
            'review_status' => $reviewStatus,
            'error_message' => null,
        ]);
        $audit->record(
            'mvp-sub-document-extracted-data-corrected',
            $actor,
            'sub_document',
            (string) $subDocument->id,
            [
                'changed_fields' => array_keys($updates),
                'review_status' => $reviewStatus->value,
            ],
            $request,
        );

        return response()->json([
            'message' => $reviewStatus === ReviewStatus::ManuallyValidated
                ? 'Dati estratti corretti e validati manualmente.'
                : 'Dati estratti aggiornati.',
            'document' => $state->document($subDocument->fresh(['originalDocument', 'extractedData'])),
            'state' => $state->forActor($actor),
        ]);
    }

    public function markReviewed(Request $request, SubDocument $subDocument, AuditLogger $audit, MvpStateService $state): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeSubDocument($subDocument, $actor);

        if (! $subDocument->extractedData) {
            throw ValidationException::withMessages([
                'subDocument' => ['Correggi i dati estratti prima di validare manualmente il sotto-documento.'],
            ]);
        }

        $subDocument->update([
            'review_status' => ReviewStatus::ManuallyValidated,
            'error_message' => null,
        ]);
        $audit->record(
            'mvp-sub-document-manually-validated',
            $actor,
            'sub_document',
            (string) $subDocument->id,
            ['review_status' => ReviewStatus::ManuallyValidated->value],
            $request,
        );

        return response()->json([
            'message' => 'Sotto-documento validato manualmente.',
            'document' => $state->document($subDocument->fresh(['originalDocument', 'extractedData'])),
            'state' => $state->forActor($actor),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function extractedDataUpdates(array $validated): array
    {
        $map = [
            'employeeFirstName' => 'employee_first_name',
            'employeeLastName' => 'employee_last_name',
            'companyName' => 'company_name',
            'documentDate' => 'document_date',
            'documentType' => 'document_type',
            'description' => 'description',
            'confidenceScore' => 'confidence_score',
            'recipientEmail' => 'recipient_email',
            'fiscalCode' => 'fiscal_code',
            'employeeId' => 'employee_id',
        ];
        $updates = [];

        foreach ($map as $requestKey => $column) {
            if (! array_key_exists($requestKey, $validated)) {
                continue;
            }

            $value = $validated[$requestKey];
            $updates[$column] = is_string($value) ? trim($value) : $value;
        }

        return $updates;
    }
}
