<?php

namespace App\Mvp\Workflow\Ports\Outbound;

/**
 * Porta secondaria condivisa fra i domini Documents e Communications: avvio
 * di un'esecuzione della macchina a stati che orchestra una pipeline
 * asincrona. Nessun riferimento a Step Functions o all'SDK AWS: un solo
 * adapter la implementa per entrambi (vedi ADR 0010).
 */
interface WorkflowEnginePort
{
    /**
     * @param  array<string, mixed>  $input
     * @return string L'ARN dell'esecuzione avviata.
     *
     * @throws \RuntimeException se l'avvio fallisce.
     */
    public function startExecution(string $stateMachineArn, string $executionName, array $input): string;
}
