<?php

namespace App\Filament\Resources\CommunicationResource\Pages;

use App\Filament\Resources\CommunicationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCommunication extends EditRecord
{
    protected static string $resource = CommunicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
