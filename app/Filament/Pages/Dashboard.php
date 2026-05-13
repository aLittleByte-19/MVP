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
            'bedrock_enabled' => $this->environmentBoolean('BEDROCK_ENABLED'),
            'aws_access_key_id' => '',
            'aws_secret_access_key' => '',
            'aws_session_token' => '',
            'aws_default_region' => $this->environmentValue('AWS_DEFAULT_REGION', 'eu-north-1'),
            'bedrock_model_id' => $this->environmentValue('BEDROCK_MODEL_ID', 'amazon.nova-lite-v1:0'),
            'document_ocr_driver' => $this->environmentValue('DOCUMENT_OCR_DRIVER', 'local'),
            'document_classifier_driver' => $this->environmentValue('DOCUMENT_CLASSIFIER_DRIVER', 'fake'),
            'textract_enabled' => $this->environmentBoolean('TEXTRACT_ENABLED'),
            'textract_aws_region' => $this->environmentValue('TEXTRACT_AWS_REGION', $this->environmentValue('AWS_DEFAULT_REGION', 'eu-north-1')),
            'poc_confidence_threshold' => (int) $this->environmentValue('POC_CONFIDENCE_THRESHOLD', 80),
        ];
    }

    public function save(): void
    {
        $state = $this->settings ?? [];
        $bedrockEnabled = filter_var($state['bedrock_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $textractEnabled = filter_var($state['textract_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $threshold = max(0, min(100, (int) ($state['poc_confidence_threshold'] ?? 80)));

        if (! $this->settingsAreValid($state, $bedrockEnabled, $textractEnabled)) {
            return;
        }

        $updates = [
            'BEDROCK_ENABLED' => $bedrockEnabled ? 'true' : 'false',
            'AWS_DEFAULT_REGION' => trim((string) ($state['aws_default_region'] ?? 'eu-north-1')),
            'BEDROCK_MODEL_ID' => trim((string) ($state['bedrock_model_id'] ?? 'amazon.nova-lite-v1:0')),
            'DOCUMENT_OCR_DRIVER' => $state['document_ocr_driver'] ?? 'local',
            'DOCUMENT_CLASSIFIER_DRIVER' => $state['document_classifier_driver'] ?? 'fake',
            'TEXTRACT_ENABLED' => $textractEnabled ? 'true' : 'false',
            'TEXTRACT_AWS_REGION' => trim((string) ($state['textract_aws_region'] ?? $state['aws_default_region'] ?? 'eu-north-1')),
            'POC_CONFIDENCE_THRESHOLD' => (string) $threshold,
        ];

        if (filled($state['aws_access_key_id'] ?? null)) {
            $updates['AWS_ACCESS_KEY_ID'] = trim((string) $state['aws_access_key_id']);
        }

        if (filled($state['aws_secret_access_key'] ?? null)) {
            $updates['AWS_SECRET_ACCESS_KEY'] = $state['aws_secret_access_key'];
        }

        if (filled($state['aws_session_token'] ?? null)) {
            $updates['AWS_SESSION_TOKEN'] = $state['aws_session_token'];
        }

        $this->writeEnvironment($updates);
        $this->settings['aws_access_key_id'] = '';
        $this->settings['aws_secret_access_key'] = '';
        $this->settings['aws_session_token'] = '';

        Artisan::call('config:clear');

        Notification::make()
            ->title('Configurazione salvata')
            ->body('I valori sono stati scritti nel file .env.')
            ->success()
            ->send();
    }

    public function clearAwsCredentials(): void
    {
        $this->writeEnvironment([
            'BEDROCK_ENABLED' => 'false',
            'DOCUMENT_CLASSIFIER_DRIVER' => 'fake',
            'AWS_ACCESS_KEY_ID' => '',
            'AWS_SECRET_ACCESS_KEY' => '',
            'AWS_SESSION_TOKEN' => '',
        ]);

        $this->settings['bedrock_enabled'] = false;
        $this->settings['document_classifier_driver'] = 'fake';
        $this->settings['aws_access_key_id'] = '';
        $this->settings['aws_secret_access_key'] = '';
        $this->settings['aws_session_token'] = '';

        Artisan::call('config:clear');

        Notification::make()
            ->title('Credenziali AWS rimosse')
            ->success()
            ->send();
    }

    public function useSimulationDefaults(): void
    {
        $this->settings = array_merge($this->settings ?? [], [
            'bedrock_enabled' => false,
            'document_classifier_driver' => 'fake',
            'document_ocr_driver' => 'local',
            'textract_enabled' => false,
            'poc_confidence_threshold' => 80,
        ]);

        Notification::make()
            ->title('Preset simulazione applicato')
            ->body('Salva la configurazione per scrivere questi valori nel file .env.')
            ->info()
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
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents)) {
                $contents = preg_replace($pattern, $line, $contents);
            } else {
                $contents = rtrim($contents).PHP_EOL.$line.PHP_EOL;
            }
        }

        File::put($envPath, $contents);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function settingsAreValid(array $state, bool $bedrockEnabled, bool $textractEnabled): bool
    {
        $accessKey = trim((string) ($state['aws_access_key_id'] ?? ''));
        $hasKey = filled($accessKey) || filled($this->environmentValue('AWS_ACCESS_KEY_ID'));
        $hasSecret = filled($state['aws_secret_access_key'] ?? null) || filled($this->environmentValue('AWS_SECRET_ACCESS_KEY'));
        $classifierDriver = $state['document_classifier_driver'] ?? 'fake';
        $ocrDriver = $state['document_ocr_driver'] ?? 'local';

        if ($bedrockEnabled && (! $hasKey || ! $hasSecret)) {
            Notification::make()
                ->title('Credenziali AWS incomplete')
                ->body('Per usare Bedrock reale servono almeno access key ID e secret access key.')
                ->danger()
                ->send();

            return false;
        }

        if ($classifierDriver === 'bedrock' && ! $bedrockEnabled) {
            Notification::make()
                ->title('Analisi Bedrock non coerente')
                ->body('Abilita Bedrock reale oppure lascia Analisi documenti su Simulata.')
                ->warning()
                ->send();

            return false;
        }

        if ($ocrDriver === 'textract' && ! $textractEnabled) {
            Notification::make()
                ->title('OCR Textract non coerente')
                ->body('Abilita Textract reale oppure lascia OCR su Locale / simulato.')
                ->warning()
                ->send();

            return false;
        }

        return true;
    }

    public function getAwsCredentialsStatusProperty(): string
    {
        $hasKey = filled($this->settings['aws_access_key_id'] ?? null) || filled($this->environmentValue('AWS_ACCESS_KEY_ID'));
        $hasSecret = filled($this->settings['aws_secret_access_key'] ?? null) || filled($this->environmentValue('AWS_SECRET_ACCESS_KEY'));

        return $hasKey && $hasSecret ? 'Configurate' : 'Mancanti';
    }

    /**
     * @return array<int, array{label: string, configured: bool}>
     */
    public function getAwsCredentialRowsProperty(): array
    {
        return [
            [
                'label' => 'Access key ID',
                'configured' => filled($this->settings['aws_access_key_id'] ?? null) || filled($this->environmentValue('AWS_ACCESS_KEY_ID')),
            ],
            [
                'label' => 'Secret access key',
                'configured' => filled($this->settings['aws_secret_access_key'] ?? null) || filled($this->environmentValue('AWS_SECRET_ACCESS_KEY')),
            ],
            [
                'label' => 'Session token',
                'configured' => filled($this->settings['aws_session_token'] ?? null) || filled($this->environmentValue('AWS_SESSION_TOKEN')),
            ],
        ];
    }

    /**
     * @return array{bedrock: string, credentials: string, analysis: string, ocr: string}
     */
    public function getRuntimeStatusProperty(): array
    {
        return [
            'bedrock' => $this->environmentBoolean('BEDROCK_ENABLED') ? 'Reale' : 'Simulato',
            'credentials' => $this->awsCredentialsStatus,
            'analysis' => $this->environmentValue('DOCUMENT_CLASSIFIER_DRIVER', 'fake') === 'bedrock' ? 'Bedrock' : 'Simulata',
            'ocr' => $this->environmentValue('DOCUMENT_OCR_DRIVER', 'local') === 'textract' ? 'Textract' : 'Locale',
        ];
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

    private function environmentBoolean(string $key, bool $default = false): bool
    {
        return filter_var($this->environmentValue($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOL);
    }

    private function environmentValue(string $key, mixed $default = null): mixed
    {
        $envPath = base_path('.env');

        if (File::exists($envPath)) {
            $contents = File::get($envPath);

            if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches)) {
                $value = trim($matches[1]);

                if ($value === '') {
                    return '';
                }

                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    return stripslashes(substr($value, 1, -1));
                }

                return $value;
            }
        }

        return env($key, $default);
    }
}
