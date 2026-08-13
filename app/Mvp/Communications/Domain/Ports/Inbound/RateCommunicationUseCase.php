<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\Exceptions\CommunicationAlreadyRatedException;
use App\Mvp\Support\Identity\Actor;

interface RateCommunicationUseCase
{
    /**
     * @throws CommunicationAlreadyRatedException
     */
    public function rate(int $communicationId, int $rating, ?string $comment, Actor $actor): void;
}
