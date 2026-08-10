<?php

namespace App\Mvp\Documents\Domain\Ports\Inbound;

use App\Mvp\Documents\Domain\ValueObjects\SubDocumentPage;

/**
 * Porta primaria: storico filtrabile dei sotto-documenti di un tenant
 * (UC-35..UC-38).
 */
interface ListDocumentsUseCase
{
    /**
     * @param  array{
     *     search?: ?string,
     *     sendStatus?: ?string,
     *     confidenceThreshold?: ?int,
     *     confidenceCriterion?: ?string,
     *     month?: ?int,
     *     year?: ?int,
     * }  $filters
     */
    public function list(string $tenantId, array $filters, int $page, int $perPage): SubDocumentPage;
}
