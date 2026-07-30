<?php

namespace App\Http\Requests\Copilot;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RateCommunicationRequest extends FormRequest
{
    public const COMMENT_MAX_LENGTH = 1000;

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
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['sometimes', 'nullable', 'string', 'max:'.self::COMMENT_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Seleziona un punteggio da 1 a 5 stelle.',
            'rating.between' => 'Il punteggio deve essere compreso tra 1 e 5 stelle.',
            'comment.max' => 'Il commento supera la lunghezza massima consentita (:max caratteri).',
        ];
    }
}
