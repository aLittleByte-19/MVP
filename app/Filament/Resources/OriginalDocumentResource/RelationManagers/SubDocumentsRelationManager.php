<?php

namespace App\Filament\Resources\OriginalDocumentResource\RelationManagers;

use App\Enums\SendStatus;
use App\Filament\Resources\OriginalDocumentResource;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SubDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'subDocuments';

    protected static ?string $title = 'Sotto-documenti';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                Tables\Columns\TextColumn::make('extractedData.employee_first_name')
                    ->label('Nome')
                    ->default('—'),
                Tables\Columns\TextColumn::make('extractedData.employee_last_name')
                    ->label('Cognome')
                    ->default('—'),
                Tables\Columns\TextColumn::make('start_page')
                    ->label('Pagine')
                    ->formatStateUsing(fn ($record) => "{$record->start_page}–{$record->end_page}"),
                Tables\Columns\TextColumn::make('send_status')
                    ->label('Invio')
                    ->badge()
                    ->color(fn (SendStatus $state): string => $state->color()),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Visualizza')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => OriginalDocumentResource::getUrl('view-sub-document', ['record' => $record->id])),
            ]);
    }
}
