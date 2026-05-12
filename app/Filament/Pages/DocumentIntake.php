<?php

namespace App\Filament\Pages;

use App\Models\OriginalDocument;
use App\Services\DocumentProcessingService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class DocumentIntake extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-up';

    protected static ?string $navigationGroup = 'Co-Pilot CdL';

    protected static ?string $navigationLabel = 'Caricamento';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Co-Pilot CdL';

    protected static string $view = 'filament.pages.document-intake';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public ?int $processedDocumentId = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Carica PDF')
                    ->description('Il sistema salva il documento, prova lo split iniziale e registra i campi rilevati.')
                    ->schema([
                        Forms\Components\FileUpload::make('document')
                            ->label('Documento PDF')
                            ->disk('local')
                            ->directory('documents/originals')
                            ->acceptedFileTypes(['application/pdf'])
                            ->maxSize(10240)
                            ->preserveFilenames()
                            ->storeFileNamesIn('original_filename')
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function process(DocumentProcessingService $documents): void
    {
        $state = $this->form->getState();
        $path = $this->firstValue($state['document'] ?? null);
        $filename = $this->firstValue($state['original_filename'] ?? null) ?: basename((string) $path);

        if (! is_string($path) || $path === '') {
            Notification::make()
                ->title('Documento mancante')
                ->body('Seleziona un PDF prima di avviare il processamento.')
                ->warning()
                ->send();

            return;
        }

        try {
            $original = $documents->handleStoredFile($path, $filename);
            $this->processedDocumentId = $original->id;
            $this->form->fill();

            Notification::make()
                ->title('Documento analizzato')
                ->body('Split iniziale e campi OCR sono disponibili nelle risorse Filament.')
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Analisi non disponibile')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getProcessedDocumentProperty(): ?OriginalDocument
    {
        if (! $this->processedDocumentId) {
            return OriginalDocument::query()
                ->with(['subDocuments.extractedData'])
                ->latest()
                ->first();
        }

        return OriginalDocument::query()
            ->with(['subDocuments.extractedData'])
            ->find($this->processedDocumentId);
    }

    private function firstValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return reset($value) ?: null;
        }

        return $value;
    }
}
