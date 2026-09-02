<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

use App\Mvp\Documents\Domain\ValueObjects\DocumentListFilters;
use App\Mvp\Documents\Domain\ValueObjects\SubDocumentPage;

/**
 * Porta primaria: storico filtrabile dei sotto-documenti di un tenant
 * (UC-35..UC-38).
 */
interface ListDocumentsUseCase
{
    public function list(string $tenantId, DocumentListFilters $filters, int $page, int $perPage): SubDocumentPage;
}
