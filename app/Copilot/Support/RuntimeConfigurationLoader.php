<?php

namespace App\Copilot\Support;

use Aws\Exception\AwsException;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Ssm\SsmClient;

class RuntimeConfigurationLoader
{
    /**
     * @var array<int, string>
     */
    private const REQUIRED_KEYS = [
        'APP_KEY',
        'DB_CONNECTION',
        'DB_HOST',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'QUEUE_CONNECTION',
        'FILESYSTEM_DISK',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_DEFAULT_REGION',
        'AWS_BUCKET',
        'AWS_ENDPOINT',
        'SQS_ENDPOINT',
        'SQS_PREFIX',
        'SQS_QUEUE',
        'SQS_DLQ_URL',
        'MVP_DOCUMENT_DISK',
        'DOCUMENT_PIPELINE_STATE_MACHINE_ARN',
        'DOCUMENT_PIPELINE_TASK_QUEUE_URL',
        'BEDROCK_MODEL_ID',
    ];

    /**
     * @var array<string, string>
     */
    private static array $collected = [];

    public static function load(): void
    {
        if (self::source() !== 'aws') {
            return;
        }

        if (self::applyCachedValues()) {
            return;
        }

        $region = self::bootstrapValue('CONFIG_AWS_REGION') ?: self::bootstrapValue('AWS_DEFAULT_REGION') ?: 'eu-north-1';
        $endpoint = self::bootstrapValue('CONFIG_AWS_ENDPOINT');
        $parametersPath = rtrim(self::bootstrapValue('CONFIG_SSM_PATH') ?: '/mvp/app', '/');
        $secretIds = array_values(array_filter(array_map(
            trim(...),
            explode(',', self::bootstrapValue('CONFIG_SECRET_IDS') ?: '/mvp/app/runtime')
        )));

        $clientConfig = [
            'version' => 'latest',
            'region' => $region,
        ];

        if ($endpoint !== '') {
            $clientConfig['endpoint'] = $endpoint;
        }

        $credentials = self::bootstrapCredentials();
        if ($credentials !== []) {
            $clientConfig['credentials'] = $credentials;
        }

        try {
            self::loadParameters(new SsmClient($clientConfig), $parametersPath);
            self::loadSecrets(new SecretsManagerClient($clientConfig), $secretIds);
            self::assertRequiredKeys();
        } catch (AwsException $exception) {
            throw new \RuntimeException('Runtime configuration could not be loaded from AWS configuration stores: '.$exception->getAwsErrorMessage(), previous: $exception);
        }

        self::persistCache();
    }

    /**
     * PHP-FPM riesegue il bootstrap a ogni richiesta: senza una cache locale
     * ogni request farebbe round-trip verso SSM/Secrets Manager (latenza e
     * throttling su AWS reale). La cache vive nel filesystem del container e
     * si rigenera quando il container viene ricreato: `make refresh-runtime`
     * resta quindi il flusso per propagare modifiche alla configurazione.
     */
    private static function applyCachedValues(): bool
    {
        $path = self::cachePath();

        if (! is_file($path)) {
            return false;
        }

        $cached = @include $path;

        if (! is_array($cached)
            || ($cached['fingerprint'] ?? null) !== self::fingerprint()
            || ! is_array($cached['values'] ?? null)) {
            return false;
        }

        foreach ($cached['values'] as $key => $value) {
            self::setRuntimeValue((string) $key, (string) $value);
        }

        return true;
    }

    private static function persistCache(): void
    {
        $path = self::cachePath();
        $payload = '<?php return '.var_export([
            'fingerprint' => self::fingerprint(),
            'values' => self::$collected,
        ], true).';';

        $temporary = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        // La cache è solo un'ottimizzazione: se la scrittura fallisce si
        // continua a leggere da AWS a ogni bootstrap.
        if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
            return;
        }

        @chmod($temporary, 0600);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);
        }
    }

    private static function cachePath(): string
    {
        $override = self::bootstrapValue('CONFIG_CACHE_PATH');

        return $override !== '' ? $override : dirname(__DIR__, 3).'/bootstrap/cache/runtime-config.php';
    }

    private static function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            self::bootstrapValue('CONFIG_AWS_REGION'),
            self::bootstrapValue('CONFIG_AWS_ENDPOINT'),
            self::bootstrapValue('CONFIG_SSM_PATH'),
            self::bootstrapValue('CONFIG_SECRET_IDS'),
        ]));
    }

    private static function source(): string
    {
        return strtolower(self::bootstrapValue('CONFIG_SOURCE') ?: 'env');
    }

    /**
     * @return array<string, string>
     */
    private static function bootstrapCredentials(): array
    {
        $key = self::bootstrapValue('CONFIG_AWS_ACCESS_KEY_ID');
        $secret = self::bootstrapValue('CONFIG_AWS_SECRET_ACCESS_KEY');
        $token = self::bootstrapValue('CONFIG_AWS_SESSION_TOKEN');

        if ($key === '' || $secret === '') {
            return [];
        }

        return array_filter([
            'key' => $key,
            'secret' => $secret,
            'token' => $token,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    private static function loadParameters(SsmClient $client, string $path): void
    {
        $nextToken = null;

        do {
            $arguments = [
                'Path' => $path,
                'Recursive' => false,
                'WithDecryption' => true,
            ];

            if ($nextToken !== null) {
                $arguments['NextToken'] = $nextToken;
            }

            $result = $client->getParametersByPath($arguments);

            foreach ($result->get('Parameters') ?? [] as $parameter) {
                $name = basename((string) ($parameter['Name'] ?? ''));
                $value = (string) ($parameter['Value'] ?? '');

                if ($name !== '') {
                    self::setRuntimeValue($name, $value);
                }
            }

            $nextToken = $result->get('NextToken');
        } while ($nextToken);
    }

    /**
     * @param  array<int, string>  $secretIds
     */
    private static function loadSecrets(SecretsManagerClient $client, array $secretIds): void
    {
        foreach ($secretIds as $secretId) {
            $result = $client->getSecretValue(['SecretId' => $secretId]);
            $secret = (string) ($result->get('SecretString') ?? '');
            $decoded = json_decode($secret, true);

            if (! is_array($decoded)) {
                throw new \RuntimeException("Runtime secret [{$secretId}] must contain a JSON object.");
            }

            foreach ($decoded as $name => $value) {
                if (is_scalar($value) || $value === null) {
                    self::setRuntimeValue((string) $name, (string) $value);
                }
            }
        }
    }

    private static function assertRequiredKeys(): void
    {
        $missing = array_values(array_filter(
            self::REQUIRED_KEYS,
            static fn (string $key): bool => self::bootstrapValue($key) === ''
        ));

        if ($missing !== []) {
            throw new \RuntimeException('Runtime configuration is incomplete. Missing keys: '.implode(', ', $missing));
        }
    }

    private static function setRuntimeValue(string $key, string $value): void
    {
        self::$collected[$key] = $value;
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    private static function bootstrapValue(string $key): string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($value) ? $value : '';
    }
}
