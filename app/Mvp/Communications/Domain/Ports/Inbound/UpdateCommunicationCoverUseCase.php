<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\Exceptions\CommunicationNotEditableException;
use App\Mvp\Identity\MvpUser;

interface UpdateCommunicationCoverUseCase
{
    /**
     * @throws CommunicationNotEditableException
     */
    public function update(int $communicationId, string $bytes, string $mime, int $size, MvpUser $actor): void;

    /**
     * @throws CommunicationNotEditableException
     */
    public function remove(int $communicationId, MvpUser $actor): void;
}
