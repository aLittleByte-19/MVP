<?php

namespace App\Http\Requests\Copilot;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateCommunicationRequest extends FormRequest
{
    private const TONES = [
        'Chiaro e diretto',
        'Più istituzionale',
        'Più sintetico',
        'Empatico',
        'Tecnico',
    ];

    private const STYLES = [
        'Testo informativo',
        'Avviso operativo',
        'Aggiornamento breve',
    ];

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
            'prompt' => ['required', 'string', 'min:12', 'max:5000'],
            'tone' => ['required', 'string', Rule::in(self::TONES)],
            'style' => ['required', 'string', Rule::in(self::STYLES)],
        ];
    }
}
