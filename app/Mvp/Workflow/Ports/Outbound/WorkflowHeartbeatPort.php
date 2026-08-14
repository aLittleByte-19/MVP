<?php

namespace App\Mvp\Workflow\Ports\Outbound;

/**
 * Porta verso il heartbeat Step Functions. I casi d'uso di lunga durata
 * (split PDF, OCR) chiamano beat() senza conoscere SfnClient: fuori da un
 * worker il canale resta inerte.
 */
interface WorkflowHeartbeatPort
{
    public function beat(bool $force = false): void;
}
