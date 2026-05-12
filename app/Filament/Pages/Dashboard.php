<?php

namespace App\Filament\Pages;

use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Configurazione';

    protected static ?string $title = 'Amministrazione PoC';

    protected static string $view = 'filament.pages.dashboard';

    /** @var array<string, mixed> */
    public ?array $settings = [];

    public function mount(): void
    {
        $this->settings = [
            'bedrock_enabled' => filter_var(env('BEDROCK_ENABLED', false), FILTER_VALIDATE_BOOL),
            'aws_access_key_id' => env('AWS_ACCESS_KEY_ID', ''),
            'aws_secret_access_key' => '',
            'aws_session_token' => '',
            'aws_default_region' => env('AWS_DEFAULT_REGION', 'eu-north-1'),
            'bedrock_model_id' => env('BEDROCK_MODEL_ID', 'amazon.nova-lite-v1:0'),
            'document_ocr_driver' => env('DOCUMENT_OCR_DRIVER', 'local'),
            'document_classifier_driver' => env('DOCUMENT_CLASSIFIER_DRIVER', 'fake'),
            'textract_enabled' => filter_var(env('TEXTRACT_ENABLED', false), FILTER_VALIDATE_BOOL),
            'textract_aws_region' => env('TEXTRACT_AWS_REGION', env('AWS_DEFAULT_REGION', 'eu-north-1')),
            'poc_confidence_threshold' => (int) env('POC_CONFIDENCE_THRESHOLD', 80),
        ];
    }

    public function save(): void
    {
        $state = $this->settings ?? [];

        $updates = [
            'BEDROCK_ENABLED' => filter_var($state['bedrock_enabled'] ?? false, FILTER_VALIDATE_BOOL) ? 'true' : 'false',
            'AWS_ACCESS_KEY_ID' => $state['aws_access_key_id'] ?? '',
            'AWS_DEFAULT_REGION' => $state['aws_default_region'] ?? 'eu-north-1',
            'BEDROCK_MODEL_ID' => $state['bedrock_model_id'] ?? 'amazon.nova-lite-v1:0',
            'DOCUMENT_OCR_DRIVER' => $state['document_ocr_driver'] ?? 'local',
            'DOCUMENT_CLASSIFIER_DRIVER' => $state['document_classifier_driver'] ?? 'fake',
            'TEXTRACT_ENABLED' => filter_var($state['textract_enabled'] ?? false, FILTER_VALIDATE_BOOL) ? 'true' : 'false',
            'TEXTRACT_AWS_REGION' => $state['textract_aws_region'] ?? $state['aws_default_region'] ?? 'eu-north-1',
            'POC_CONFIDENCE_THRESHOLD' => (string) ($state['poc_confidence_threshold'] ?? 80),
        ];

        if (filled($state['aws_secret_access_key'] ?? null)) {
            $updates['AWS_SECRET_ACCESS_KEY'] = $state['aws_secret_access_key'];
        }

        if (filled($state['aws_session_token'] ?? null)) {
            $updates['AWS_SESSION_TOKEN'] = $state['aws_session_token'];
        }

        $this->writeEnvironment($updates);

        Artisan::call('config:clear');

        Notification::make()
            ->title('Configurazione salvata')
            ->body('I valori sono stati scritti nel file .env.')
            ->success()
            ->send();
    }

    public function resetData(): void
    {
        Artisan::call('poc:reset-data', ['--force' => true]);

        Notification::make()
            ->title('Dati di elaborazione resettati')
            ->success()
            ->send();
    }

    /**
     * @param  array<string, string>  $updates
     */
    private function writeEnvironment(array $updates): void
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath) && File::exists(base_path('.env.example'))) {
            File::copy(base_path('.env.example'), $envPath);
        }

        $contents = File::exists($envPath) ? File::get($envPath) : '';

        foreach ($updates as $key => $value) {
            $line = $key.'='.$this->formatEnvironmentValue($value);

            if (preg_match("/^{$key}=.*$/m", $contents)) {
                $contents = preg_replace("/^{$key}=.*$/m", $line, $contents);
            } else {
                $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
            }
        }

        File::put($envPath, $contents);
    }

    private function formatEnvironmentValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (in_array(strtolower($value), ['true', 'false', 'null'], true) || is_numeric($value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
