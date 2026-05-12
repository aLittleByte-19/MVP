<?php

namespace App\Filament\Resources\CommunicationResource\Pages;

use App\Filament\Resources\CommunicationResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCommunication extends ViewRecord
{
    protected static string $resource = CommunicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
