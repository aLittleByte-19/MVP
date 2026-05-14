<?php

namespace App\Poc\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveSettingsRequest extends FormRequest
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
            'bedrock_enabled' => ['nullable', 'boolean'],
            'aws_access_key_id' => ['nullable', 'string'],
            'aws_secret_access_key' => ['nullable', 'string'],
            'aws_session_token' => ['nullable', 'string'],
            'aws_default_region' => ['required', 'string', 'max:80'],
            'bedrock_model_id' => ['required', 'string', 'max:200'],
            'document_ocr_driver' => ['required', 'in:local,bedrock'],
            'document_classifier_driver' => ['required', 'in:fake,bedrock'],
            'textract_enabled' => ['nullable', 'boolean'],
            'textract_aws_region' => ['nullable', 'string', 'max:80'],
            'poc_confidence_threshold' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'bedrock_enabled' => $this->boolean('bedrock_enabled'),
            'textract_enabled' => $this->boolean('textract_enabled'),
        ]);
    }
}
