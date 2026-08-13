<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Support\Identity\Actor;

interface DeleteCommunicationUseCase
{
    public function delete(int $communicationId, Actor $actor): void;
}
