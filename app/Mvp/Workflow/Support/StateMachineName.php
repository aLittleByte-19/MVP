<?php

namespace App\Mvp\Workflow\Support;

use Illuminate\Support\Str;

/**
 * Nome breve di una state machine a partire dal suo ARN.
 *
 * Era duplicato come metodo privato identico in StartDocumentWorkflowService e
 * StartCommunicationWorkflowService; serve ora anche a WorkflowTaskRunner, che
 * deve etichettare i fallimenti di task con la stessa `state_machine` usata dai
 * due casi d'uso di avvio — altrimenti la stessa metrica esce con insiemi di
 * label diversi e le query che filtrano per state_machine non vedono meta' dei
 * fallimenti.
 *
 * Sta in Workflow\Support, infrastruttura condivisa fra i due domini (ADR 0010):
 * una funzione pura sul formato di un ARN, non una porta.
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
