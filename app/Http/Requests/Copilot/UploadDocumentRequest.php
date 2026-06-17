<?php

namespace App\Http\Requests\Copilot;

use App\Copilot\Audit\Services\AuditLogger;
use App\Copilot\Identity\PocUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Process;
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

                if (! $this->hasPdfMagicBytes($file->getPathname())) {
                    $validator->errors()->add('document', 'Il file non contiene una firma PDF valida.');

                    return;
                }

                if (! $this->hasPdfMimeType($file->getPathname())) {
                    $validator->errors()->add('document', 'Il MIME reale del file non è compatibile con PDF.');

                    return;
                }

                if ($this->isEncryptedPdf($file->getPathname())) {
                    $validator->errors()->add('document', 'I PDF cifrati o protetti da password non sono supportati.');

                    return;
                }

                if (! $this->passesStructuralCheck($file->getPathname())) {
                    $validator->errors()->add('document', 'Il PDF non supera la validazione strutturale (qpdf --check).');

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

    /**
     * Traccia il rifiuto in audit e delega la risposta al rendering globale
     * delle ValidationException, cosi' l'envelope errori resta in un punto solo.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        $actor = $this->user();
        app(AuditLogger::class)->record(
            'poc-document-upload-rejected',
            $actor instanceof PocUser ? $actor : null,
            'original_document',
            null,
            [
                'filename' => $this->file('document')?->getClientOriginalName(),
                'errors' => $validator->errors()->toArray(),
            ],
            $this,
        );

        parent::failedValidation($validator);
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $integer === false ? null : (int) $integer;
    }

    private function hasPdfMagicBytes(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if (! is_resource($handle)) {
            return false;
        }

        try {
            return fread($handle, 5) === '%PDF-';
        } finally {
            fclose($handle);
        }
    }

    private function hasPdfMimeType(string $path): bool
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return in_array($mime, ['application/pdf', 'application/x-pdf'], true);
    }

    /**
     * Con qpdf disponibile la cifratura viene verificata dal parser reale
     * (exit 0 = cifrato, 2 = non cifrato). Senza qpdf si ricade su una
     * euristica ristretta alla coda del file, dove vive il trailer che
     * referenzia /Encrypt: scansionare l'intero file produrrebbe falsi
     * positivi sui content stream.
     */
    private function isEncryptedPdf(string $path): bool
    {
        $qpdf = $this->qpdfBinary();

        if ($qpdf !== null) {
            return Process::timeout(10)->run([$qpdf, '--is-encrypted', $path])->exitCode() === 0;
        }

        return str_contains($this->fileTail($path, 4096), '/Encrypt');
    }

    /**
     * qpdf --check valida struttura e cross-reference. I soli warning non
     * bloccano (--warning-exit-0): molti PDF reali ne producono. Se qpdf non
     * e' presente il controllo e' delegato al parse FPDI piu' avanti.
     */
    private function passesStructuralCheck(string $path): bool
    {
        $qpdf = $this->qpdfBinary();

        if ($qpdf === null) {
            return true;
        }

        return Process::timeout(15)->run([$qpdf, '--check', '--warning-exit-0', $path])->exitCode() === 0;
    }

    private function qpdfBinary(): ?string
    {
        $configured = (string) config('poc.document_limits.qpdf_binary', '');
        $candidates = $configured !== ''
            ? [$configured]
            : ['/usr/bin/qpdf', '/usr/local/bin/qpdf', '/opt/homebrew/bin/qpdf'];

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function fileTail(string $path, int $bytes): string
    {
        $size = filesize($path);

        if ($size === false) {
            return '';
        }

        $contents = file_get_contents($path, offset: max(0, $size - $bytes));

        return is_string($contents) ? $contents : '';
    }
}
