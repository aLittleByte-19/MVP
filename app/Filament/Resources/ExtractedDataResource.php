<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ExtractedDataResource\Pages;
use App\Models\ExtractedData;
use App\Models\SubDocument;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExtractedDataResource extends Resource
{
    protected static ?string $model = ExtractedData::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Co-Pilot CdL';

    protected static ?string $modelLabel = 'dato estratto';

    protected static ?string $pluralModelLabel = 'dati estratti';

    protected static ?int $navigationSort = 23;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Sotto-documento')
                    ->schema([
                        Forms\Components\Select::make('sub_document_id')
                            ->label('Sotto-documento')
                            ->relationship(
                                name: 'subDocument',
                                titleAttribute: 'id',
                                modifyQueryUsing: fn (Builder $query) => $query->with('originalDocument'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (SubDocument $record): string => sprintf(
                                '#%d - %s',
                                $record->id,
                                $record->originalDocument?->original_filename ?? 'documento',
                            ))
                            ->searchable()
                            ->preload()
                            ->required(),
                    ]),
                Forms\Components\Section::make('Campi rilevati')
                    ->schema([
                        Forms\Components\TextInput::make('employee_first_name')
                            ->label('Nome dipendente')
                            ->maxLength(200),
                        Forms\Components\TextInput::make('employee_last_name')
                            ->label('Cognome dipendente')
                            ->maxLength(200),
                        Forms\Components\TextInput::make('company_name')
                            ->label('Azienda')
                            ->maxLength(500),
                        Forms\Components\DatePicker::make('document_date')
                            ->label('Data documento'),
                        Forms\Components\TextInput::make('document_type')
                            ->label('Tipo documento')
                            ->maxLength(200),
                        Forms\Components\TextInput::make('confidence_score')
                            ->label('Confidenza')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                        Forms\Components\Textarea::make('description')
                            ->label('Descrizione')
                            ->rows(4)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee')
                    ->label('Dipendente')
                    ->state(function (ExtractedData $record): string {
                        $name = trim(implode(' ', array_filter([
                            $record->employee_first_name,
                            $record->employee_last_name,
                        ])));

                        return $name !== '' ? $name : 'Da verificare';
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

                        return $query
                            ->where('employee_first_name', $operator, "%{$search}%")
                            ->orWhere('employee_last_name', $operator, "%{$search}%");
                    }),
                Tables\Columns\TextColumn::make('company_name')
                    ->label('Azienda')
                    ->placeholder('Non rilevata')
                    ->searchable(),
                Tables\Columns\TextColumn::make('document_type')
                    ->label('Tipo')
                    ->placeholder('Non rilevato')
                    ->searchable(),
                Tables\Columns\TextColumn::make('document_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('confidence_score')
                    ->label('Confidenza')
                    ->suffix('%')
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        blank($state) => 'gray',
                        (int) $state < (int) env('POC_CONFIDENCE_THRESHOLD', 80) => 'warning',
                        default => 'success',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('subDocument.originalDocument.original_filename')
                    ->label('File')
                    ->limit(36)
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['subDocument.originalDocument']);
    }

    public static function getNavigationBadge(): ?string
    {
        $threshold = (int) env('POC_CONFIDENCE_THRESHOLD', 80);

        return (string) static::getModel()::query()
            ->where(fn (Builder $query) => $query
                ->whereNull('confidence_score')
                ->orWhere('confidence_score', '<', $threshold))
            ->count();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListExtractedData::route('/'),
            'create' => Pages\CreateExtractedData::route('/create'),
            'view' => Pages\ViewExtractedData::route('/{record}'),
            'edit' => Pages\EditExtractedData::route('/{record}/edit'),
        ];
    }
}
