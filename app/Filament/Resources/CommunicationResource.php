<?php

namespace App\Filament\Resources;

use App\Enums\CommunicationStatus;
use App\Filament\Resources\CommunicationResource\Pages;
use App\Models\Communication;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CommunicationResource extends Resource
{
    protected static ?string $model = Communication::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'AI Assistant';

    protected static ?string $modelLabel = 'comunicazione';

    protected static ?string $pluralModelLabel = 'comunicazioni';

    protected static ?int $navigationSort = 11;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Contenuto')
                    ->schema([
                        Forms\Components\Textarea::make('prompt')
                            ->label('Prompt')
                            ->required()
                            ->rows(5)
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('generated_title')
                            ->label('Titolo')
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('generated_body')
                            ->label('Testo')
                            ->rows(10)
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Section::make('Parametri')
                    ->schema([
                        Forms\Components\Select::make('tone')
                            ->label('Tono')
                            ->options([
                                'Chiaro e diretto' => 'Chiaro e diretto',
                                'Più istituzionale' => 'Più istituzionale',
                                'Più sintetico' => 'Più sintetico',
                                'Empatico' => 'Empatico',
                                'Tecnico' => 'Tecnico',
                            ])
                            ->required(),
                        Forms\Components\Select::make('style')
                            ->label('Stile')
                            ->options([
                                'Testo informativo' => 'Testo informativo',
                                'Avviso operativo' => 'Avviso operativo',
                                'Aggiornamento breve' => 'Aggiornamento breve',
                            ])
                            ->required(),
                        Forms\Components\Select::make('status')
                            ->label('Stato')
                            ->options(self::statusOptions())
                            ->default(CommunicationStatus::Draft->value)
                            ->required(),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('generated_title')
                    ->label('Titolo')
                    ->placeholder('Senza titolo')
                    ->limit(52)
                    ->searchable(),
                Tables\Columns\TextColumn::make('tone')
                    ->label('Tono')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('style')
                    ->label('Stile')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (CommunicationStatus $state): string => $state->label())
                    ->color(fn (CommunicationStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creata')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options(self::statusOptions()),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approva')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(fn (Communication $record): bool => $record->status !== CommunicationStatus::Approved)
                    ->action(fn (Communication $record) => $record->update(['status' => CommunicationStatus::Approved])),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::query()->where('status', CommunicationStatus::Draft)->count();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommunications::route('/'),
            'create' => Pages\CreateCommunication::route('/create'),
            'view' => Pages\ViewCommunication::route('/{record}'),
            'edit' => Pages\EditCommunication::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(CommunicationStatus::cases())
            ->mapWithKeys(fn (CommunicationStatus $status): array => [$status->value => $status->label()])
            ->all();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }
}
