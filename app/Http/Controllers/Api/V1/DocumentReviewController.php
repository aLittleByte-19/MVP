<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\AuthorizesDocuments;
use App\Http\Controllers\Api\V1\Concerns\ResolvesActor;
use App\Http\Requests\UpdateExtractedDataRequest;
use App\Models\SubDocument;
use App\Mvp\Documents\Domain\Exceptions\MissingExtractedDataException;
use App\Mvp\Documents\Domain\Ports\Inbound\ReviewDocumentUseCase;
use App\Mvp\Support\MvpStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Adapter primario HTTP: revisione human-in-the-loop. Traduce la richiesta
 * nel caso d'uso tramite la sua porta primaria; nessuna regola di business
 * qui (vedi ADR 0010).
 */
class DocumentReviewController
{
    use AuthorizesDocuments, ResolvesActor;

    public function updateExtractedData(
        UpdateExtractedDataRequest $request,
        SubDocument $subDocument,
        ReviewDocumentUseCase $review,
        MvpStateService $state,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->authorizeSubDocument($subDocument, $actor);

        $validated = $request->validated();
        $fieldUpdates = $this->extractedDataUpdates($validated);
        $markAsValidated = (bool) ($validated['markAsValidated'] ?? false);

        $reviewStatus = $review->updateExtractedData($subDocument->id, $fieldUpdates, $markAsValidated, $actor);

        return response()->json([
            'message' => $reviewStatus === 'manually_validated'
                ? 'Dati estratti corretti e validati manualmente.'
                : 'Dati estratti aggiornati.',
            'document' => $state->document($subDocument->fresh(['originalDocument', 'extractedData'])),
            'state' => $state->forActor($actor),
        ]);
    }

    public function markReviewed(Request $request, SubDocument $subDocument, ReviewDocumentUseCase $review, MvpStateService $state): JsonResponse
    {
        $actor = $this->actor($request);
        $this->authorizeSubDocument($subDocument, $actor);

        try {
            $review->markReviewed($subDocument->id, $actor);
        } catch (MissingExtractedDataException $e) {
            throw ValidationException::withMessages(['subDocument' => [$e->getMessage()]]);
        }

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
