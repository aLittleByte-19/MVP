<?php

namespace App\Http\Requests;

use App\Mvp\Documents\Rules\ValidCodiceFiscale;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExtractedDataRequest extends FormRequest
{
    /**
     * Tipologie ammesse per "documentType" (UC-43) — tenere allineate alla
     * descrizione del campo `documentType` nello schema
     * UpdateExtractedDataRequest in openapi/v1 (non un `enum` OpenAPI:
     * {@see self::allowedDocumentTypes()} ammette anche un valore fuori da
     * queste sei se gia' presente sul record, quindi l'insieme valido
     * dipende dalla richiesta e non e' esprimibile come enum fisso).
     */
    public const DOCUMENT_TYPES = ['cedolino', 'CU', 'comunicazione', 'documento da firmare', 'lettera', 'altro'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'employeeFirstName' => ['sometimes', 'nullable', 'string', 'max:200'],
            'employeeLastName' => ['sometimes', 'nullable', 'string', 'max:200'],
            'companyName' => ['sometimes', 'nullable', 'string', 'max:500'],
            'documentDate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'documentType' => ['sometimes', 'nullable', 'string', Rule::in($this->allowedDocumentTypes())],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'confidenceScore' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'markAsValidated' => ['sometimes', 'boolean'],
            'recipientEmail' => ['nullable', 'email', 'max:255'],
            'fiscalCode' => ['nullable', 'string', 'size:16', new ValidCodiceFiscale],
            'employeeId' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Le 6 tipologie fisse (UC-43) più, se presente, la classificazione libera
     * già assegnata dall'AI: il classificatore Bedrock non è vincolato
     * all'enum, quindi senza questa eccezione un semplice "Salva" senza
     * toccare il campo romperebbe qualunque documento già classificato con
     * un valore fuori enum.
     *
     * @return array<int, string>
     */
    private function allowedDocumentTypes(): array
    {
        $current = $this->route('subDocument')?->extractedData?->document_type;

        if ($current && ! in_array($current, self::DOCUMENT_TYPES, true)) {
            return [...self::DOCUMENT_TYPES, $current];
        }

        return self::DOCUMENT_TYPES;
    }
}
