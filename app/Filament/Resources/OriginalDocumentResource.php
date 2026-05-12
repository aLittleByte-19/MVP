<?php

namespace App\Filament\Resources;

use App\Enums\ProcessingStatus;
use App\Filament\Resources\OriginalDocumentResource\Pages;
use App\Models\OriginalDocument;
use App\Services\DocumentProcessingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OriginalDocumentResource extends Resource
{
    protected static ?string $model = OriginalDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Co-Pilot CdL';

    protected static ?string $modelLabel = 'documento originale';

    protected static ?string $pluralModelLabel = 'documenti originali';

    protected static ?int $navigationSort = 21;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Documento')
                    ->schema([
                        Forms\Components\FileUpload::make('file_path')
                            ->label('PDF')
                            ->disk('local')
                            ->directory('documents/originals')
                            ->acceptedFileTypes(['application/pdf'])
                            ->maxSize(10240)
                            ->preserveFilenames()
                            ->storeFileNamesIn('original_filename')
                            ->required()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('original_filename')
                            ->label('Nome file originale')
                            ->required()
                            ->maxLength(500),
                        Forms\Components\Select::make('processing_status')
                            ->label('Stato processamento')
                            ->options(self::statusOptions())
                            ->default(ProcessingStatus::Pending->value)
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('File')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('processing_status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (ProcessingStatus $state): string => $state->label())
                    ->color(fn (ProcessingStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('sub_documents_count')
                    ->label('Split')
                    ->counts('subDocuments')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Caricato')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('processing_status')
                    ->label('Stato')
                    ->options(self::statusOptions()),
            ])
            ->actions([
                Tables\Actions\Action::make('process')
                    ->label('Processa')
                    ->icon('heroicon-m-cog-6-tooth')
                    ->requiresConfirmation()
                    ->action(function (OriginalDocument $record): void {
                        try {
                            app(DocumentProcessingService::class)->process($record);

                            Notification::make()
                                ->title('Documento processato')
                                ->success()
                                ->send();
                        } catch (\Throwable $exception) {
                            Notification::make()
                                ->title('Processamento fallito')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
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
            ->withCount('subDocuments');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOriginalDocuments::route('/'),
            'create' => Pages\CreateOriginalDocument::route('/create'),
            'view' => Pages\ViewOriginalDocument::route('/{record}'),
            'edit' => Pages\EditOriginalDocument::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(ProcessingStatus::cases())
            ->mapWithKeys(fn (ProcessingStatus $status): array => [$status->value => $status->label()])
            ->all();
    }
}
