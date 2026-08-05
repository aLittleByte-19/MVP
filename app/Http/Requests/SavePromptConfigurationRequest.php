<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePromptConfigurationRequest extends FormRequest
{
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
            'name' => ['nullable', 'string', 'max:150'],
            'prompt' => ['required', 'string', 'min:12', 'max:5000'],
            'tone' => ['required', 'string', Rule::in(GenerateCommunicationRequest::TONES)],
            'style' => ['required', 'string', Rule::in(GenerateCommunicationRequest::STYLES)],
        ];
    }
}
