<?php

namespace App\Filament\Resources\CommunicationResource\Pages;

use App\Enums\CommunicationStatus;
use App\Filament\Resources\CommunicationResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCommunication extends ViewRecord
{
    protected static string $resource = CommunicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('discard')
                ->label('Scarta')
                ->color('danger')
                ->icon('heroicon-o-trash')
                ->requiresConfirmation()
                ->modalHeading('Scarta comunicazione')
                ->modalDescription('Sei sicuro di voler scartare questa comunicazione? L\'azione non può essere annullata.')
                ->visible(fn () => $this->record->status === CommunicationStatus::Draft)
                ->action(function () {
                    $this->record->update(['status' => CommunicationStatus::Discarded]);
                    $this->refreshFormData(['status']);
                }),
        ];
    }
}
