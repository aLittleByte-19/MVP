<?php

namespace App\Filament\Resources\OriginalDocumentResource\Pages;

use App\Filament\Resources\OriginalDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewOriginalDocument extends ViewRecord
{
    protected static string $resource = OriginalDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
