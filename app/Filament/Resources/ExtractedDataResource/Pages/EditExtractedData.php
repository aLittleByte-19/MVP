<?php

namespace App\Filament\Resources\ExtractedDataResource\Pages;

use App\Filament\Resources\ExtractedDataResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditExtractedData extends EditRecord
{
    protected static string $resource = ExtractedDataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
