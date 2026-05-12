<?php

namespace App\Filament\Resources;

use App\Enums\SendStatus;
use App\Filament\Resources\SubDocumentResource\Pages;
use App\Models\SubDocument;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubDocumentResource extends Resource
{
    protected static ?string $model = SubDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = 'Co-Pilot CdL';

    protected static ?string $modelLabel = 'sotto-documento';

    protected static ?string $pluralModelLabel = 'sotto-documenti';

    protected static ?int $navigationSort = 22;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Split')
                    ->schema([
                        Forms\Components\Select::make('original_document_id')
                            ->label('Documento originale')
                            ->relationship('originalDocument', 'original_filename')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\FileUpload::make('file_path')
                            ->label('PDF split')
                            ->disk('local')
                            ->directory('documents/sub')
                            ->acceptedFileTypes(['application/pdf'])
                            ->maxSize(10240)
                            ->required(),
                        Forms\Components\TextInput::make('start_page')
                            ->label('Pagina iniziale')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Forms\Components\TextInput::make('end_page')
                            ->label('Pagina finale')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        Forms\Components\Select::make('send_status')
                            ->label('Stato invio')
                            ->options(self::statusOptions())
                            ->default(SendStatus::Pending->value)
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('originalDocument.original_filename')
                    ->label('File originale')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('employee')
                    ->label('Dipendente')
                    ->state(function (SubDocument $record): string {
                        $name = trim(implode(' ', array_filter([
                            $record->extractedData?->employee_first_name,
                            $record->extractedData?->employee_last_name,
                        ])));

                        return $name !== '' ? $name : 'Da verificare';
                    })
                    ->searchable(false),
                Tables\Columns\TextColumn::make('pages')
                    ->label('Pagine')
                    ->state(fn (SubDocument $record): string => "{$record->start_page}-{$record->end_page}"),
                Tables\Columns\TextColumn::make('extractedData.document_type')
                    ->label('Tipo')
                    ->placeholder('Non rilevato')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('send_status')
                    ->label('Invio')
                    ->badge()
                    ->formatStateUsing(fn (SendStatus $state): string => $state->label())
                    ->color(fn (SendStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creato')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('preview')
                    ->label('Anteprima')
                    ->icon('heroicon-m-eye')
                    ->url(fn (SubDocument $record): string => route('poc.documents.preview', ['subDocument' => $record]))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('markSent')
                    ->label('Segna inviato')
                    ->icon('heroicon-m-paper-airplane')
                    ->color('success')
                    ->visible(fn (SubDocument $record): bool => $record->send_status !== SendStatus::Sent)
                    ->action(fn (SubDocument $record) => $record->update(['send_status' => SendStatus::Sent])),
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
            ->with(['originalDocument', 'extractedData']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubDocuments::route('/'),
            'create' => Pages\CreateSubDocument::route('/create'),
            'view' => Pages\ViewSubDocument::route('/{record}'),
            'edit' => Pages\EditSubDocument::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(SendStatus::cases())
            ->mapWithKeys(fn (SendStatus $status): array => [$status->value => $status->label()])
            ->all();
    }
}
