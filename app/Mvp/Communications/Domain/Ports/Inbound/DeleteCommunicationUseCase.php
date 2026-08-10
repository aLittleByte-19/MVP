<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Identity\MvpUser;

interface DeleteCommunicationUseCase
{
    public function delete(int $communicationId, MvpUser $actor): void;
}
