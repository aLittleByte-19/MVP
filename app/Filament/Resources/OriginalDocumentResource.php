<?php

namespace App\Filament\Resources;

use App\Enums\ProcessingStatus;
use App\Filament\Resources\OriginalDocumentResource\Pages;
use App\Filament\Resources\OriginalDocumentResource\RelationManagers\SubDocumentsRelationManager;
use App\Models\OriginalDocument;
use App\Services\DocumentProcessingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class OriginalDocumentResource extends Resource
{
    protected static ?string $model = OriginalDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-up';

    protected static ?string $navigationLabel = 'AI Copilot';

    protected static ?string $modelLabel = 'Documento';

    protected static ?string $pluralModelLabel = 'AI Copilot';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Documento originale')
                    ->schema([
                        Infolists\Components\TextEntry::make('original_filename')
                            ->label('Nome file'),
                        Infolists\Components\TextEntry::make('processing_status')
                            ->label('Stato elaborazione')
                            ->badge()
                            ->color(fn (ProcessingStatus $state): string => $state->color()),
                        Infolists\Components\TextEntry::make('created_at')
                            ->label('Caricato il')
                            ->dateTime('d/m/Y H:i'),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('original_filename')
                    ->label('Nome file')
                    ->searchable(),
                Tables\Columns\TextColumn::make('processing_status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn (ProcessingStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('sub_documents_count')
                    ->label('Sotto-documenti')
                    ->counts('subDocuments'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Caricato il')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('upload')
                    ->label('Carica PDF')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->modalHeading('Carica documento PDF')
                    ->form([
                        Forms\Components\FileUpload::make('pdf_file')
                            ->label('File PDF')
                            ->acceptedFileTypes(['application/pdf'])
                            ->required()
                            ->disk('local')
                            ->directory('documents/originals'),
                    ])
                    ->action(function (array $data) {
                        $service = app(DocumentProcessingService::class);
                        $file = new UploadedFile(
                            Storage::disk('local')->path($data['pdf_file']),
                            basename($data['pdf_file']),
                            'application/pdf',
                            null,
                            true,
                        );
                        $service->handleUpload($file);
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('processing_status')
                    ->label('Stato')
                    ->options(ProcessingStatus::class),
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
        return [
            SubDocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOriginalDocuments::route('/'),
            'view' => Pages\ViewOriginalDocument::route('/{record}'),
            'view-sub-document' => Pages\ViewSubDocument::route('/sub-documents/{record}'),
        ];
    }
}
