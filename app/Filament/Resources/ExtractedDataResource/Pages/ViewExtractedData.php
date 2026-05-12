<?php

namespace App\Filament\Resources\ExtractedDataResource\Pages;

use App\Filament\Resources\ExtractedDataResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewExtractedData extends ViewRecord
{
    protected static string $resource = ExtractedDataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
