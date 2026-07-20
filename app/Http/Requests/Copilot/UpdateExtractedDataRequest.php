<?php

namespace App\Http\Requests\Copilot;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExtractedDataRequest extends FormRequest
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
            'employeeFirstName' => ['sometimes', 'nullable', 'string', 'max:200'],
            'employeeLastName' => ['sometimes', 'nullable', 'string', 'max:200'],
            'companyName' => ['sometimes', 'nullable', 'string', 'max:500'],
            'documentDate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'documentType' => ['sometimes', 'nullable', 'string', 'max:200'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'confidenceScore' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'markAsValidated' => ['sometimes', 'boolean'],
            'recipientEmail' => ['nullable', 'email', 'max:255'],
            'fiscalCode' => ['nullable', 'string', 'size:16'], 
            'employeeId' => ['nullable', 'string', 'max:255'],
        ];
    }
}
