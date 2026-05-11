<?php

namespace App\Filament\Resources;

use App\Enums\CommunicationStatus;
use App\Filament\Resources\CommunicationResource\Pages;
use App\Models\Communication;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CommunicationResource extends Resource
{
    protected static ?string $model = Communication::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'AI Generativa';

    protected static ?string $modelLabel = 'Comunicazione';

    protected static ?string $pluralModelLabel = 'AI Generativa';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Textarea::make('prompt')
                    ->label('Contenuto richiesto')
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('tone')
                    ->label('Tono')
                    ->required()
                    ->maxLength(100),
                Forms\Components\TextInput::make('style')
                    ->label('Stile')
                    ->required()
                    ->maxLength(100),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Parametri')
                    ->schema([
                        Infolists\Components\TextEntry::make('prompt')
                            ->label('Contenuto richiesto')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('tone')
                            ->label('Tono'),
                        Infolists\Components\TextEntry::make('style')
                            ->label('Stile'),
                        Infolists\Components\TextEntry::make('status')
                            ->label('Stato')
                            ->badge()
                            ->color(fn (CommunicationStatus $state): string => $state->color()),
                    ])
                    ->columns(2),
                Infolists\Components\Section::make('Testo generato')
                    ->schema([
                        Infolists\Components\TextEntry::make('generated_title')
                            ->label('Titolo')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('generated_body')
                            ->label('Corpo')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('generated_title')
                    ->label('Titolo')
                    ->limit(60)
                    ->searchable(),
                Tables\Columns\TextColumn::make('tone')
                    ->label('Tono')
                    ->searchable(),
                Tables\Columns\TextColumn::make('style')
                    ->label('Stile')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (CommunicationStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creata il')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(CommunicationStatus::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommunications::route('/'),
            'create' => Pages\CreateCommunication::route('/create'),
            'view' => Pages\ViewCommunication::route('/{record}'),
        ];
    }
}
