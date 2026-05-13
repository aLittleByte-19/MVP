<?php

namespace App\Poc\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request for saving POC settings.
 */
class SaveSettingsRequest extends FormRequest
{
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
            'bedrock_enabled' => ['nullable', 'boolean'],
            'aws_access_key_id' => ['nullable', 'string'],
            'aws_secret_access_key' => ['nullable', 'string'],
            'aws_session_token' => ['nullable', 'string'],
            'aws_default_region' => ['required', 'string', 'max:80'],
            'bedrock_model_id' => ['required', 'string', 'max:200'],
            'document_ocr_driver' => ['required', 'in:local,textract'],
            'document_classifier_driver' => ['required', 'in:fake,bedrock'],
            'textract_enabled' => ['nullable', 'boolean'],
            'textract_aws_region' => ['required', 'string', 'max:80'],
            'poc_confidence_threshold' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'bedrock_enabled' => $this->boolean('bedrock_enabled'),
            'textract_enabled' => $this->boolean('textract_enabled'),
        ]);
    }
}
