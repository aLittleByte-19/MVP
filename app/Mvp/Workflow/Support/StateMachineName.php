<?php

namespace App\Mvp\Workflow\Support;

use Illuminate\Support\Str;

/**
 * Nome breve di una state machine a partire dal suo ARN. Condiviso fra i casi
 * d'uso di avvio e WorkflowTaskRunner: senza la stessa label `state_machine`
 * su avvii e fallimenti di task, le query che filtrano per essa ne perdono meta'.
 */
final class StateMachineName
{
    public const UNKNOWN = 'unknown';

    public static function fromArn(string $arn): string
    {
        return Str::of($arn)->afterLast(':')->toString() ?: self::UNKNOWN;
    }

    /**
     * Nome breve della state machine di una pipeline, letto dalla
     * configurazione. `unknown` quando l'ARN non e' configurato: una label
     * esplicita e' preferibile a una label assente, che romperebbe le query
     * `sum by (state_machine)`.
     */
    public static function forPipeline(string $pipeline): string
    {
        $arn = match ($pipeline) {
            'communications' => (string) config('services.workflow.communications_state_machine_arn'),
            'documents' => (string) config('services.workflow.state_machine_arn'),
            default => '',
        };

        return $arn === '' ? self::UNKNOWN : self::fromArn($arn);
    }
}
