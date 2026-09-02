<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\ValueObjects\CommunicationListFilters;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationPage;

interface ListCommunicationsUseCase
{
    public function list(string $tenantId, CommunicationListFilters $filters, int $page, int $perPage): CommunicationPage;
}
