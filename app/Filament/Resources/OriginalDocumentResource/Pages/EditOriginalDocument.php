<?php

namespace App\Filament\Resources\OriginalDocumentResource\Pages;

use App\Filament\Resources\OriginalDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOriginalDocument extends EditRecord
{
    protected static string $resource = OriginalDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
