<?php

namespace App\Http\Requests\Copilot;

use App\Copilot\Communications\Enums\SendStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Filtri dello storico documenti (UC-35..UC-38). Tutti opzionali: assenti
     * o vuoti non restringono il risultato.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sendStatus' => ['sometimes', 'nullable', Rule::enum(SendStatus::class)],
            'confidenceThreshold' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'confidenceCriterion' => ['sometimes', 'nullable', Rule::in(['above', 'below'])],
            'month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
