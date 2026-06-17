<?php

namespace App\Copilot\Workflow\Services;

use App\Copilot\Observability\MetricsRecorder;
use Aws\Exception\AwsException;
use Aws\Sfn\SfnClient;
use Illuminate\Support\Facades\Log;

/**
 * Shared heartbeat channel for the Step Functions callback pattern.
 *
 * The consumer activates it per message; long-running services (Textract
 * polling, Bedrock segment loop) call beat() from their hot paths. Outside of
 * a worker context the service stays inactive and beat() is a no-op, so the
 * web request path never talks to Step Functions.
 */
class WorkflowTaskHeartbeat
{
    private ?string $taskToken = null;

    private string $taskType = '';

    private float $lastBeatAt = 0.0;

    private bool $degraded = false;

    public function __construct(
        private readonly SfnClient $stepFunctions,
        private readonly MetricsRecorder $metrics,
    ) {}

    /**
     * Bind the heartbeat to a task token and send the initial heartbeat so the
     * HeartbeatSeconds clock restarts as soon as the worker picks the message up.
     */
    public function activate(string $taskToken, string $taskType): void
    {
        $this->taskToken = $taskToken;
        $this->taskType = $taskType;
        $this->degraded = false;
        $this->lastBeatAt = 0.0;

        $this->beat(force: true);
    }

    public function deactivate(): void
    {
        $this->taskToken = null;
        $this->taskType = '';
        $this->degraded = false;
        $this->lastBeatAt = 0.0;
    }

    /**
     * Send a heartbeat, throttled to one call per configured interval. A
     * rejected heartbeat (task already timed out, token unknown, emulator
     * without SendTaskHeartbeat support) must not abort the business task:
     * it is logged, counted and the channel degrades to no-op for the rest
     * of the current task.
     */
    public function beat(bool $force = false): void
    {
        if ($this->taskToken === null || $this->degraded) {
            return;
        }

        $interval = max(1, (int) config('poc.workflow.heartbeat_interval_seconds', 30));
        $now = microtime(true);

        if (! $force && ($now - $this->lastBeatAt) < $interval) {
            return;
        }

        try {
            $this->stepFunctions->sendTaskHeartbeat(['taskToken' => $this->taskToken]);
            $this->lastBeatAt = $now;
            $this->metrics->recordDomainCounter('stepfunctions_heartbeats_sent_total', ['task_type' => $this->taskType]);
        } catch (AwsException $e) {
            $this->degraded = true;
            $this->metrics->recordDomainCounter('stepfunctions_heartbeats_failed_total', [
                'task_type' => $this->taskType,
                'error' => $e->getAwsErrorCode() ?: 'aws_error',
            ]);
            Log::warning('SendTaskHeartbeat rejected; continuing task without heartbeats', [
                'task_type' => $this->taskType,
                'aws_error' => $e->getAwsErrorCode(),
                'message' => $e->getAwsErrorMessage() ?: $e->getMessage(),
            ]);
        }
    }
}
