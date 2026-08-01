<?php

use App\Mvp\Support\RuntimeConfigurationLoader;
use Aws\Result;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Ssm\SsmClient;

function invokeRuntimeConfigurationLoader(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionClass(RuntimeConfigurationLoader::class))
        ->getMethod($method)
        ->invoke(null, ...$arguments);
}

function setRuntimeEnvironmentValue(string $key, string $value): void
{
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

function unsetRuntimeEnvironmentValue(string $key): void
{
    unset($_ENV[$key], $_SERVER[$key]);
    putenv($key);
}

$runtimeConfigurationKeys = array_values(array_unique(array_merge(
    (new ReflectionClass(RuntimeConfigurationLoader::class))->getConstant('REQUIRED_KEYS'),
    [
        'CONFIG_SOURCE',
        'CONFIG_AWS_REGION',
        'CONFIG_AWS_ENDPOINT',
        'CONFIG_AWS_ACCESS_KEY_ID',
        'CONFIG_AWS_SECRET_ACCESS_KEY',
        'CONFIG_AWS_SESSION_TOKEN',
        'CONFIG_SSM_PATH',
        'CONFIG_SECRET_IDS',
        'CONFIG_CACHE_PATH',
    ],
)));

$originalRuntimeConfiguration = [];
foreach ($runtimeConfigurationKeys as $key) {
    $originalRuntimeConfiguration[$key] = getenv($key);
}

beforeEach(function () use ($runtimeConfigurationKeys, $originalRuntimeConfiguration): void {
    foreach ($runtimeConfigurationKeys as $key) {
        $original = $originalRuntimeConfiguration[$key];

        if ($original === false) {
            unsetRuntimeEnvironmentValue($key);
        } else {
            setRuntimeEnvironmentValue($key, $original);
        }
    }

    (new ReflectionClass(RuntimeConfigurationLoader::class))
        ->getProperty('collected')
        ->setValue(null, []);
});

afterEach(function () use ($runtimeConfigurationKeys, $originalRuntimeConfiguration): void {
    foreach ($runtimeConfigurationKeys as $key) {
        $original = $originalRuntimeConfiguration[$key];

        if ($original === false) {
            unsetRuntimeEnvironmentValue($key);
        } else {
            setRuntimeEnvironmentValue($key, $original);
        }
    }

    (new ReflectionClass(RuntimeConfigurationLoader::class))
        ->getProperty('collected')
        ->setValue(null, []);
});

afterAll(function () use ($runtimeConfigurationKeys, $originalRuntimeConfiguration): void {
    foreach ($runtimeConfigurationKeys as $key) {
        $original = $originalRuntimeConfiguration[$key];

        if ($original === false) {
            unsetRuntimeEnvironmentValue($key);
        } else {
            setRuntimeEnvironmentValue($key, $original);
        }
    }
});

test('runtime configuration ignores AWS stores when the source is env', function () {
    setRuntimeEnvironmentValue('CONFIG_SOURCE', 'ENV');

    RuntimeConfigurationLoader::load();

    expect(invokeRuntimeConfigurationLoader('source'))->toBe('env');
});

test('runtime configuration applies a valid cache without contacting AWS', function () {
    $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mvp-runtime-cache-'.bin2hex(random_bytes(6)).'.php';

    try {
        setRuntimeEnvironmentValue('CONFIG_SOURCE', 'aws');
        setRuntimeEnvironmentValue('CONFIG_CACHE_PATH', $cachePath);
        $fingerprint = invokeRuntimeConfigurationLoader('fingerprint');
        file_put_contents($cachePath, '<?php return '.var_export([
            'fingerprint' => $fingerprint,
            'values' => ['APP_KEY' => 'base64:cached-key', 'DB_HOST' => 'cached-db'],
        ], true).';');

        RuntimeConfigurationLoader::load();

        expect(getenv('APP_KEY'))->toBe('base64:cached-key')
            ->and(getenv('DB_HOST'))->toBe('cached-db');
    } finally {
        @unlink($cachePath);
    }
});

test('runtime configuration rejects missing malformed and stale caches', function () {
    $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mvp-runtime-cache-'.bin2hex(random_bytes(6)).'.php';
    setRuntimeEnvironmentValue('CONFIG_CACHE_PATH', $cachePath);

    try {
        expect(invokeRuntimeConfigurationLoader('applyCachedValues'))->toBeFalse();

        file_put_contents($cachePath, '<?php return "invalid";');
        expect(invokeRuntimeConfigurationLoader('applyCachedValues'))->toBeFalse();

        file_put_contents($cachePath, '<?php return '.var_export([
            'fingerprint' => 'stale',
            'values' => ['APP_KEY' => 'ignored'],
        ], true).';');
        expect(invokeRuntimeConfigurationLoader('applyCachedValues'))->toBeFalse();
    } finally {
        @unlink($cachePath);
    }
});

test('runtime configuration persists collected values and can read them back', function () {
    $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mvp-runtime-cache-'.bin2hex(random_bytes(6)).'.php';
    setRuntimeEnvironmentValue('CONFIG_CACHE_PATH', $cachePath);

    try {
        invokeRuntimeConfigurationLoader('setRuntimeValue', 'APP_KEY', 'base64:persisted');
        invokeRuntimeConfigurationLoader('persistCache');
        $payload = include $cachePath;

        expect($payload['fingerprint'])->toBe(invokeRuntimeConfigurationLoader('fingerprint'))
            ->and($payload['values']['APP_KEY'])->toBe('base64:persisted');

        unsetRuntimeEnvironmentValue('APP_KEY');
        expect(invokeRuntimeConfigurationLoader('applyCachedValues'))->toBeTrue()
            ->and(getenv('APP_KEY'))->toBe('base64:persisted');
    } finally {
        @unlink($cachePath);
    }
});

test('runtime cache failures do not interrupt bootstrap', function () {
    $cachePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'missing-mvp-directory-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'runtime.php';
    setRuntimeEnvironmentValue('CONFIG_CACHE_PATH', $cachePath);

    invokeRuntimeConfigurationLoader('persistCache');

    expect(is_file($cachePath))->toBeFalse();
});

test('runtime bootstrap credentials require a complete key pair and include an optional token', function () {
    unsetRuntimeEnvironmentValue('CONFIG_AWS_SECRET_ACCESS_KEY');
    unsetRuntimeEnvironmentValue('CONFIG_AWS_SESSION_TOKEN');
    setRuntimeEnvironmentValue('CONFIG_AWS_ACCESS_KEY_ID', 'key');
    expect(invokeRuntimeConfigurationLoader('bootstrapCredentials'))->toBe([]);

    setRuntimeEnvironmentValue('CONFIG_AWS_SECRET_ACCESS_KEY', 'secret');
    expect(invokeRuntimeConfigurationLoader('bootstrapCredentials'))->toBe([
        'key' => 'key',
        'secret' => 'secret',
    ]);

    setRuntimeEnvironmentValue('CONFIG_AWS_SESSION_TOKEN', 'token');
    expect(invokeRuntimeConfigurationLoader('bootstrapCredentials'))->toBe([
        'key' => 'key',
        'secret' => 'secret',
        'token' => 'token',
    ]);
});

test('runtime parameters support pagination and ignore entries without a name', function () {
    $client = Mockery::mock(SsmClient::class);
    $client->shouldReceive('getParametersByPath')
        ->once()
        ->with(['Path' => '/mvp/app', 'Recursive' => false, 'WithDecryption' => true])
        ->andReturn(new Result([
            'Parameters' => [
                ['Name' => '/mvp/app/DB_HOST', 'Value' => 'postgres'],
                ['Name' => '', 'Value' => 'ignored'],
            ],
            'NextToken' => 'next-page',
        ]));
    $client->shouldReceive('getParametersByPath')
        ->once()
        ->with(['Path' => '/mvp/app', 'Recursive' => false, 'WithDecryption' => true, 'NextToken' => 'next-page'])
        ->andReturn(new Result([
            'Parameters' => [['Name' => '/mvp/app/DB_DATABASE', 'Value' => 'mvp']],
        ]));

    invokeRuntimeConfigurationLoader('loadParameters', $client, '/mvp/app');

    expect(getenv('DB_HOST'))->toBe('postgres')
        ->and(getenv('DB_DATABASE'))->toBe('mvp');
});

test('runtime secrets import scalar values and ignore structured ones', function () {
    $client = Mockery::mock(SecretsManagerClient::class);
    $client->shouldReceive('getSecretValue')
        ->once()
        ->with(['SecretId' => '/mvp/app/runtime'])
        ->andReturn(new Result([
            'SecretString' => json_encode([
                'DB_USERNAME' => 'mvp',
                'DB_PASSWORD' => 1234,
                'AWS_SESSION_TOKEN' => null,
                'IGNORED' => ['nested' => true],
            ], JSON_THROW_ON_ERROR),
        ]));

    invokeRuntimeConfigurationLoader('loadSecrets', $client, ['/mvp/app/runtime']);

    expect(getenv('DB_USERNAME'))->toBe('mvp')
        ->and(getenv('DB_PASSWORD'))->toBe('1234')
        ->and(getenv('AWS_SESSION_TOKEN'))->toBe('')
        ->and(getenv('IGNORED'))->toBeFalse();
});

test('runtime secrets must contain a JSON object', function () {
    $client = Mockery::mock(SecretsManagerClient::class);
    $client->shouldReceive('getSecretValue')
        ->once()
        ->andReturn(new Result(['SecretString' => 'not-json']));

    expect(fn () => invokeRuntimeConfigurationLoader('loadSecrets', $client, ['/mvp/app/runtime']))
        ->toThrow(RuntimeException::class, 'must contain a JSON object');
});

test('runtime configuration reports all missing required keys', function () {
    $required = (new ReflectionClass(RuntimeConfigurationLoader::class))->getConstant('REQUIRED_KEYS');
    foreach ($required as $key) {
        setRuntimeEnvironmentValue($key, 'configured');
    }
    unsetRuntimeEnvironmentValue('DB_HOST');
    unsetRuntimeEnvironmentValue('SQS_DLQ_URL');

    expect(fn () => invokeRuntimeConfigurationLoader('assertRequiredKeys'))
        ->toThrow(RuntimeException::class, 'DB_HOST, SQS_DLQ_URL');

    setRuntimeEnvironmentValue('DB_HOST', 'postgres');
    setRuntimeEnvironmentValue('SQS_DLQ_URL', 'http://localstack/dlq');
    invokeRuntimeConfigurationLoader('assertRequiredKeys');
});

test('runtime cache path and fingerprint follow bootstrap overrides', function () {
    setRuntimeEnvironmentValue('CONFIG_CACHE_PATH', '/tmp/custom-runtime.php');
    setRuntimeEnvironmentValue('CONFIG_AWS_REGION', 'eu-west-1');
    setRuntimeEnvironmentValue('CONFIG_AWS_ENDPOINT', 'http://localstack:4566');
    setRuntimeEnvironmentValue('CONFIG_SSM_PATH', '/custom/path');
    setRuntimeEnvironmentValue('CONFIG_SECRET_IDS', 'one,two');

    expect(invokeRuntimeConfigurationLoader('cachePath'))->toBe('/tmp/custom-runtime.php')
        ->and(invokeRuntimeConfigurationLoader('fingerprint'))->toBe(hash('sha256', implode('|', [
            'eu-west-1',
            'http://localstack:4566',
            '/custom/path',
            'one,two',
        ])));
});
