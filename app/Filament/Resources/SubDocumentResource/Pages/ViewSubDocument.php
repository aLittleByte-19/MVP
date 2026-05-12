<?php

namespace App\Filament\Resources\SubDocumentResource\Pages;

use App\Filament\Resources\SubDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewSubDocument extends ViewRecord
{
    protected static string $resource = SubDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
