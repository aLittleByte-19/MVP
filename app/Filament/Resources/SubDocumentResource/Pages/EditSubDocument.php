<?php

namespace App\Filament\Resources\SubDocumentResource\Pages;

use App\Filament\Resources\SubDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubDocument extends EditRecord
{
    protected static string $resource = SubDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
