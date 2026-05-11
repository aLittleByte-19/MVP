<?php

namespace App\Filament\Resources\OriginalDocumentResource\Pages;

use App\Enums\SendStatus;
use App\Filament\Resources\OriginalDocumentResource;
use App\Models\SubDocument;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\Page;

class ViewSubDocument extends Page
{
    protected static string $resource = OriginalDocumentResource::class;

    protected static string $view = 'filament.resources.original-document-resource.pages.view-sub-document';

    public SubDocument $subDocument;

    public function mount(int $record): void
    {
        $this->subDocument = SubDocument::with(['extractedData', 'originalDocument'])->findOrFail($record);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('back')
                ->label('Torna al documento')
                ->icon('heroicon-o-arrow-left')
                ->url(fn () => OriginalDocumentResource::getUrl('view', ['record' => $this->subDocument->original_document_id])),
            Actions\Action::make('send')
                ->label('Invia')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Invia documento')
                ->modalDescription('Confermi l\'invio di questo sotto-documento al dipendente?')
                ->visible(fn () => $this->subDocument->send_status === SendStatus::Pending)
                ->action(function () {
                    $this->subDocument->update(['send_status' => SendStatus::Sent]);
                    $this->subDocument->refresh();
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->record($this->subDocument->extractedData)
            ->schema([
                Infolists\Components\Grid::make(2)
                    ->schema([
                        Infolists\Components\Section::make('Dati estratti')
                            ->schema([
                                Infolists\Components\TextEntry::make('employee_first_name')
                                    ->label('Nome'),
                                Infolists\Components\TextEntry::make('employee_last_name')
                                    ->label('Cognome'),
                                Infolists\Components\TextEntry::make('company_name')
                                    ->label('Azienda'),
                                Infolists\Components\TextEntry::make('document_date')
                                    ->label('Data documento')
                                    ->date('d/m/Y'),
                                Infolists\Components\TextEntry::make('document_type')
                                    ->label('Tipo documento'),
                                Infolists\Components\TextEntry::make('confidence_score')
                                    ->label('Confidenza')
                                    ->suffix('%'),
                                Infolists\Components\TextEntry::make('description')
                                    ->label('Descrizione')
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Infolists\Components\Section::make('Documento')
                            ->schema([
                                Infolists\Components\TextEntry::make('subDocument.send_status')
                                    ->label('Stato invio')
                                    ->state(fn () => $this->subDocument->send_status)
                                    ->badge()
                                    ->color(fn (SendStatus $state): string => $state->color()),
                                Infolists\Components\TextEntry::make('pages')
                                    ->label('Pagine')
                                    ->state(fn () => "{$this->subDocument->start_page}–{$this->subDocument->end_page}"),
                            ]),
                    ]),
            ]);
    }
}
