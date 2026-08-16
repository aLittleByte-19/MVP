<?php

namespace App\Mvp\Workflow\Ports\Outbound;

/**
 * Porta secondaria condivisa fra i domini Documents e Communications: avvio
 * di un'esecuzione della macchina a stati che orchestra una pipeline
 * asincrona. Nessun riferimento a Step Functions o all'SDK AWS: prima di
 * questa porta, DocumentWorkflowService e CommunicationWorkflowService
 * avvolgevano SfnClient in modo quasi identico, in due classi separate (vedi
 * ADR 0010) — un solo adapter la implementa per entrambi.
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
