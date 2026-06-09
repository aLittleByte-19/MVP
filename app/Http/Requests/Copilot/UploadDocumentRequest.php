<?php

namespace App\Http\Requests\Copilot;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use setasign\Fpdi\Fpdi;

class UploadDocumentRequest extends FormRequest
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
        $maxKilobytes = max(1, (int) config('poc.document_limits.max_upload_mb', 20)) * 1024;

        return [
            'document' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.$maxKilobytes],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('document');

                if (! $file || ! $file->isValid()) {
                    return;
                }

                $filename = $file->getClientOriginalName();

                if (basename($filename) !== $filename || str_contains($filename, '..') || preg_match('/[\/\\\\\x00]/', $filename)) {
                    $validator->errors()->add('document', 'Il nome file contiene caratteri o percorsi non consentiti.');

                    return;
                }

                $maxTextractBytes = $this->optionalPositiveInt(config('services.textract.max_bytes'));

                if ($maxTextractBytes !== null && $file->getSize() !== false && $file->getSize() > $maxTextractBytes) {
                    $validator->errors()->add('document', 'Il PDF supera il limite byte configurato per Textract.');

                    return;
                }

                try {
                    $pdf = new Fpdi;
                    $pages = $pdf->setSourceFile($file->getPathname());
                } catch (\Throwable) {
                    $validator->errors()->add('document', 'Il PDF non è leggibile o è danneggiato.');

                    return;
                }

                $maxPages = max(1, (int) config('poc.document_limits.max_pdf_pages', 50));

                if ($pages > $maxPages) {
                    $validator->errors()->add('document', "Il PDF supera il limite di {$maxPages} pagine.");
                }

                $maxTextractPages = $this->optionalPositiveInt(config('services.textract.max_pages'));

                if ($maxTextractPages !== null && $pages > $maxTextractPages) {
                    $validator->errors()->add('document', "Il PDF supera il limite Textract di {$maxTextractPages} pagine.");
                }
            },
        ];
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $integer === false ? null : (int) $integer;
    }
}
