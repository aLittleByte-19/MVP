<?php

namespace App\Http\Controllers\Api\V1\Copilot;

use App\Http\Controllers\Api\V1\Copilot\Concerns\AuthorizesDocuments;
use App\Http\Controllers\Api\V1\Copilot\Concerns\ResolvesActor;
use App\Models\Copilot\SubDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Anteprima PDF del sotto-documento, con errore applicativo leggibile quando lo storage non risponde.
 */
class DocumentPreviewController
{
    use AuthorizesDocuments, ResolvesActor;

    public function preview(Request $request, SubDocument $subDocument): StreamedResponse
    {
        if ($subDocument->originalDocument) {
            $this->authorizeOriginalDocument($subDocument->originalDocument, $this->actor($request));
        }

        $disk = Storage::disk(config('mvp.documents.storage_disk', config('filesystems.default', 'local')));

        try {
            abort_unless($disk->exists($subDocument->file_path), 404);
        } catch (FilesystemException $exception) {
            report($exception);

            abort(503, 'Storage documenti non raggiungibile.');
        }

        $filename = $subDocument->originalDocument?->original_filename ?: 'documento.pdf';

        return response()->stream(function () use ($disk, $subDocument): void {
            $stream = $disk->readStream($subDocument->file_path);

            if (! is_resource($stream)) {
                return;
            }

            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $filename).'"',
        ]);
    }
}
