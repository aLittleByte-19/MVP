<?php

namespace App\Mvp\Communications\Domain\Ports\Inbound;

interface ListCommunicationsUseCase
{
    /**
     * @param  array{keyword?: ?string, tone?: ?string, style?: ?string, date?: ?string}  $filters
     * @return array{ids: list<int>, total: int, page: int, perPage: int}
     */
    public function list(string $tenantId, array $filters, int $page, int $perPage): array;
}
