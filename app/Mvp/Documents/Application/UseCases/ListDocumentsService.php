<?php

namespace App\Mvp\Documents\Application\UseCases;

use App\Mvp\Documents\Domain\Ports\Inbound\ListDocumentsUseCase;
use App\Mvp\Documents\Domain\Ports\Outbound\DocumentRepository;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentPage;

class ListDocumentsService implements ListDocumentsUseCase
{
    public function __construct(private readonly DocumentRepository $documents) {}

    public function list(string $tenantId, array $filters, int $page, int $perPage): SubDocumentPage
    {
        return $this->documents->paginateSubDocuments($tenantId, $filters, $page, $perPage);
    }
}
