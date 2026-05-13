<?php

namespace App\Poc\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Request for uploading a document.
 */
class UploadDocumentRequest extends FormRequest
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
            'document' => ['required', 'file', 'mimetypes:application/pdf', 'max:10240'],
        ];
    }
}
