<?php

namespace App\Filament\Resources\OriginalDocumentResource\Pages;

use App\Filament\Resources\OriginalDocumentResource;
use App\Services\DocumentProcessingService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateOriginalDocument extends CreateRecord
{
    protected static string $resource = OriginalDocumentResource::class;

    protected function afterCreate(): void
    {
        try {
            app(DocumentProcessingService::class)->process($this->record);

            Notification::make()
                ->title('Documento processato')
                ->body('Split iniziale e campi rilevati sono stati salvati.')
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Documento salvato, analisi fallita')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }
}
