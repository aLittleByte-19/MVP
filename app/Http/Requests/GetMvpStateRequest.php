<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GetMvpStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Filtri della sezione Metriche dell'AI Assistant (RF38-OB..RF41-OB).
     * Tutti opzionali: assenti o vuoti non restringono il risultato.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'style' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dateFrom' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'dateTo' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
        ];
    }
}
