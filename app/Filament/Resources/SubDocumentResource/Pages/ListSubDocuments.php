<?php

namespace App\Filament\Resources\SubDocumentResource\Pages;

use App\Filament\Resources\SubDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSubDocuments extends ListRecords
{
    protected static string $resource = SubDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
