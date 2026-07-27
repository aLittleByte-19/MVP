<?php

namespace App\Http\Requests\Copilot;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCommunicationCoverRequest extends FormRequest
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
        $maxKilobytes = max(1, (int) config('mvp.communications.cover_max_mb', 5)) * 1024;

        return [
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.$maxKilobytes,
            ],
        ];
    }
}
