<?php

namespace App\Filament\Pages;

use App\Enums\CommunicationStatus;
use App\Models\Communication;
use App\Services\BedrockService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AiAssistant extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'AI Assistant';

    protected static ?string $navigationLabel = 'Generazione';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'AI Assistant';

    protected static string $view = 'filament.pages.ai-assistant';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public ?int $generatedCommunicationId = null;

    public function mount(): void
    {
        $this->form->fill([
            'tone' => 'Chiaro e diretto',
            'style' => 'Testo informativo',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Nuova bozza')
                    ->description('Inserisci il contenuto da generare e scegli solo tono e stile.')
                    ->schema([
                        Forms\Components\Textarea::make('prompt')
                            ->label('Prompt')
                            ->placeholder('Descrivi il contenuto da generare')
                            ->required()
                            ->minLength(12)
                            ->maxLength(5000)
                            ->rows(6)
                            ->columnSpanFull(),
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
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function generate(BedrockService $bedrock): void
    {
        $state = $this->form->getState();

        try {
            $generated = $bedrock->generateCommunication(
                $state['prompt'],
                $state['tone'],
                $state['style'],
            );

            $communication = Communication::create([
                'prompt' => $state['prompt'],
                'tone' => $state['tone'],
                'style' => $state['style'],
                'generated_title' => $generated['title'],
                'generated_body' => $generated['body'],
                'status' => CommunicationStatus::Draft,
            ]);

            $this->generatedCommunicationId = $communication->id;

            Notification::make()
                ->title('Bozza generata')
                ->body('Il contenuto e stato salvato tra le comunicazioni.')
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Generazione non disponibile')
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getGeneratedCommunicationProperty(): ?Communication
    {
        if (! $this->generatedCommunicationId) {
            return Communication::query()->latest()->first();
        }

        return Communication::query()->find($this->generatedCommunicationId);
    }
}
