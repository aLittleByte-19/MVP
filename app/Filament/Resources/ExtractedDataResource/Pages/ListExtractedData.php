<?php

namespace App\Filament\Resources\ExtractedDataResource\Pages;

use App\Filament\Resources\ExtractedDataResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExtractedData extends ListRecords
{
    protected static string $resource = ExtractedDataResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
