<?php

namespace App\Poc\Controllers;

use App\Poc\Requests\SaveSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class AdminController
{
    public function index(): View
    {
        $settings = $this->settings();

        return view('poc.admin', [
            'settings' => $settings,
            'awsCredentialsStatus' => $this->awsCredentialsStatus(),
            'awsCredentialRows' => $this->awsCredentialRows(),
            'runtimeStatus' => $this->runtimeStatus(),
        ]);
    }

    public function save(SaveSettingsRequest $request): RedirectResponse
    {
        $state = $request->validated();

        $bedrockEnabled = $state['bedrock_enabled'];
        if (! $this->settingsAreValid($state, $bedrockEnabled)) {
            return back()->withInput();
        }

        $updates = [
            'BEDROCK_ENABLED' => $bedrockEnabled ? 'true' : 'false',
            'AWS_DEFAULT_REGION' => trim((string) $state['aws_default_region']),
            'BEDROCK_MODEL_ID' => trim((string) $state['bedrock_model_id']),
            'DOCUMENT_OCR_DRIVER' => $state['document_ocr_driver'],
            'DOCUMENT_CLASSIFIER_DRIVER' => $state['document_classifier_driver'],
            'TEXTRACT_ENABLED' => 'false',
            'TEXTRACT_AWS_REGION' => trim((string) ($state['textract_aws_region'] ?? $this->environmentValue('TEXTRACT_AWS_REGION', 'eu-north-1'))),
            'POC_CONFIDENCE_THRESHOLD' => (string) max(0, min(100, (int) $state['poc_confidence_threshold'])),
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
        $this->refreshRuntimeConfiguration();

        return redirect()
            ->route('admin.index')
            ->with('status', 'Configurazione salvata. I nuovi job useranno i valori aggiornati.');
    }

    public function useSimulationDefaults(): RedirectResponse
    {
        $this->writeEnvironment([
            'BEDROCK_ENABLED' => 'false',
            'DOCUMENT_CLASSIFIER_DRIVER' => 'fake',
            'DOCUMENT_OCR_DRIVER' => 'local',
            'TEXTRACT_ENABLED' => 'false',
            'POC_CONFIDENCE_THRESHOLD' => '80',
        ]);

        $this->refreshRuntimeConfiguration();

        return redirect()
            ->route('admin.index')
            ->with('status', 'Preset simulazione applicato.');
    }

    public function clearAwsCredentials(): RedirectResponse
    {
        $this->writeEnvironment([
            'BEDROCK_ENABLED' => 'false',
            'DOCUMENT_CLASSIFIER_DRIVER' => 'fake',
            'AWS_ACCESS_KEY_ID' => '',
            'AWS_SECRET_ACCESS_KEY' => '',
            'AWS_SESSION_TOKEN' => '',
            'DOCUMENT_OCR_DRIVER' => 'local',
        ]);

        $this->refreshRuntimeConfiguration();

        return redirect()
            ->route('admin.index')
            ->with('status', 'Credenziali AWS rimosse.');
    }

    public function resetData(): RedirectResponse
    {
        Artisan::call('poc:reset-data', ['--force' => true]);

        return redirect()
            ->route('admin.index')
            ->with('status', 'Dati di elaborazione resettati.');
    }

    /**
     * Normalized settings shown by the admin Blade view.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
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

    private function awsCredentialsStatus(): string
    {
        $hasKey = filled($this->environmentValue('AWS_ACCESS_KEY_ID'));
        $hasSecret = filled($this->environmentValue('AWS_SECRET_ACCESS_KEY'));

        return $hasKey && $hasSecret ? 'Configurate' : 'Mancanti';
    }

    /**
     * @return array<int, array{label: string, configured: bool}>
     */
    private function awsCredentialRows(): array
    {
        return [
            [
                'label' => 'Access key ID',
                'configured' => filled($this->environmentValue('AWS_ACCESS_KEY_ID')),
            ],
            [
                'label' => 'Secret access key',
                'configured' => filled($this->environmentValue('AWS_SECRET_ACCESS_KEY')),
            ],
            [
                'label' => 'Session token',
                'configured' => filled($this->environmentValue('AWS_SESSION_TOKEN')),
            ],
        ];
    }

    /**
     * @return array{bedrock: string, credentials: string, analysis: string, ocr: string, queue: string, storage: string}
     */
    private function runtimeStatus(): array
    {
        return [
            'bedrock' => $this->environmentBoolean('BEDROCK_ENABLED') ? 'Reale' : 'Simulato',
            'credentials' => $this->awsCredentialsStatus(),
            'analysis' => $this->environmentValue('DOCUMENT_CLASSIFIER_DRIVER', 'fake') === 'bedrock' ? 'Bedrock' : 'Simulata',
            'ocr' => $this->environmentValue('DOCUMENT_OCR_DRIVER', 'local') === 'bedrock' ? 'Bedrock' : 'Locale',
            'queue' => $this->environmentValue('QUEUE_CONNECTION', 'sync') === 'redis' ? 'Redis' : 'Sincrona',
            'storage' => $this->environmentValue('FILESYSTEM_DISK', 'local') === 's3' ? 'MinIO / S3' : 'Locale',
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function settingsAreValid(array $state, bool $bedrockEnabled): bool
    {
        $accessKey = trim((string) ($state['aws_access_key_id'] ?? ''));
        $hasKey = filled($accessKey) || filled($this->environmentValue('AWS_ACCESS_KEY_ID'));
        $hasSecret = filled($state['aws_secret_access_key'] ?? null) || filled($this->environmentValue('AWS_SECRET_ACCESS_KEY'));
        $classifierDriver = $state['document_classifier_driver'] ?? 'fake';
        $ocrDriver = $state['document_ocr_driver'] ?? 'local';

        if ($bedrockEnabled && (! $hasKey || ! $hasSecret)) {
            session()->flash('error', 'Per usare Bedrock reale servono almeno access key ID e secret access key.');

            return false;
        }

        if ($classifierDriver === 'bedrock' && ! $bedrockEnabled) {
            session()->flash('error', 'Abilita Bedrock reale oppure lascia Analisi documenti su Simulata.');

            return false;
        }

        if ($ocrDriver === 'bedrock' && ! $bedrockEnabled) {
            session()->flash('error', 'Abilita Bedrock reale oppure lascia OCR su Locale / simulato.');

            return false;
        }

        return true;
    }

    /**
     * Values are escaped for .env before being written as scalar strings.
     *
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

    private function refreshRuntimeConfiguration(): void
    {
        Artisan::call('config:clear');
        Artisan::call('queue:restart');
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

    /**
     * Read directly from .env so the admin page reflects pending config-file edits.
     */
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
