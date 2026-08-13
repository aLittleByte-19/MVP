<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

use App\Mvp\Communications\Domain\ValueObjects\CommunicationPage;

interface ListCommunicationsUseCase
{
    /**
     * @param  array{keyword?: ?string, tone?: ?string, style?: ?string, date?: ?string}  $filters
     */
    public function list(string $tenantId, array $filters, int $page, int $perPage): CommunicationPage;
}
