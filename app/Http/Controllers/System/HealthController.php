<?php

namespace App\Http\Controllers\System;

use Aws\Sqs\SqsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthController
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => config('app.name'),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'config' => $this->checkConfiguration(),
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
        ];

        $ready = collect($checks)->every(fn (array $check): bool => in_array($check['status'], ['ok', 'skipped'], true));

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $ready ? 200 : 503);
    }

    /**
     * @return array{status: string, message?: string}
     */
    private function checkConfiguration(): array
    {
        $missing = [];

        if (! filled(config('app.key'))) {
            $missing[] = 'APP_KEY';
        }

        if (config('filesystems.default') === 's3' && ! filled(config('filesystems.disks.s3.bucket'))) {
            $missing[] = 'AWS_BUCKET';
        }

        if (config('queue.default') === 'sqs' && ! filled(config('queue.connections.sqs.queue'))) {
            $missing[] = 'SQS_QUEUE';
        }

        if ($missing !== []) {
            return [
                'status' => 'failed',
                'message' => 'Missing required configuration: '.implode(', ', $missing),
            ];
        }

        return ['status' => 'ok'];
    }

    /**
     * @return array{status: string, message?: string}
     */
    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'message' => $this->safeMessage($e),
            ];
        }
    }

    /**
     * @return array{status: string, message?: string}
     */
    private function checkRedis(): array
    {
        $usesRedis = config('cache.default') === 'redis'
            || config('session.driver') === 'redis'
            || config('database.redis.client') !== null;

        if (! $usesRedis || app()->runningUnitTests()) {
            return ['status' => 'skipped'];
        }

        try {
            Redis::connection()->ping();

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'message' => $this->safeMessage($e),
            ];
        }
    }

    /**
     * @return array{status: string, message?: string}
     */
    private function checkQueue(): array
    {
        if (config('queue.default') !== 'sqs') {
            return ['status' => 'skipped'];
        }

        $queue = config('queue.connections.sqs');

        if (! filled($queue['key'] ?? null) || ! filled($queue['secret'] ?? null)) {
            return [
                'status' => 'failed',
                'message' => 'SQS credentials are not configured for readiness checks.',
            ];
        }

        try {
            $clientConfig = [
                'version' => 'latest',
                'region' => $queue['region'],
                'credentials' => [
                    'key' => $queue['key'],
                    'secret' => $queue['secret'],
                    'token' => $queue['token'] ?? null,
                ],
                'http' => [
                    'connect_timeout' => 2,
                    'timeout' => 3,
                ],
            ];

            if (filled($queue['endpoint'] ?? null)) {
                $clientConfig['endpoint'] = $queue['endpoint'];
            }

            $client = new SqsClient($clientConfig);
            $client->getQueueAttributes([
                'QueueUrl' => rtrim((string) $queue['prefix'], '/').'/'.$queue['queue'],
                'AttributeNames' => ['QueueArn'],
            ]);

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return [
                'status' => 'failed',
                'message' => $this->safeMessage($e),
            ];
        }
    }

    private function safeMessage(\Throwable $e): string
    {
        return str($e->getMessage())
            ->replaceMatches('/(AWS_ACCESS_KEY_ID|AWS_SECRET_ACCESS_KEY|AWS_SESSION_TOKEN)=\S+/i', '$1=[redacted]')
            ->limit(220)
            ->toString();
    }
}
