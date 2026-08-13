<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Support\Identity\Actor;

interface UpdateCommunicationCoverUseCase
{
    /**
     * @throws CommunicationNotEditableException
     */
    public function update(int $communicationId, string $bytes, string $mime, int $size, Actor $actor): void;

    /**
     * @throws CommunicationNotEditableException
     */
    public function remove(int $communicationId, Actor $actor): void;
}
