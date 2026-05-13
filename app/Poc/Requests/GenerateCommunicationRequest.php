<?php

namespace App\Poc\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request for generating a communication.
 */
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

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
