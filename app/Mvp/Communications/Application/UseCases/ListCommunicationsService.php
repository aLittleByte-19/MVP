<?php

namespace App\Mvp\Communications\Application\UseCases;

use App\Mvp\Communications\Domain\Ports\Inbound\ListCommunicationsUseCase;
use App\Mvp\Communications\Domain\Ports\Outbound\CommunicationRepository;
use App\Mvp\Communications\Domain\ValueObjects\CommunicationPage;

class ListCommunicationsService implements ListCommunicationsUseCase
{
    public function __construct(private readonly CommunicationRepository $communications) {}

    public function list(string $tenantId, array $filters, int $page, int $perPage): CommunicationPage
    {
        return $this->communications->paginateApprovedCommunications($tenantId, $filters, $page, $perPage);
    }
}
