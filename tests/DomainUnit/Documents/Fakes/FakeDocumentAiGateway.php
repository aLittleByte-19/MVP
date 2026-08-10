<?php

namespace Tests\DomainUnit\Documents\Fakes;

use App\Mvp\Documents\Domain\Ports\Outbound\DocumentAiGatewayPort;

final class FakeDocumentAiGateway implements DocumentAiGatewayPort
{
    /** @var array<int, array{employee_name: string, start_page: int, end_page: int}> */
    private array $segments = [];

    /** @var array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}|null */
    private ?array $fields = null;

    private ?\Throwable $extractException = null;

    /**
     * @param  array<int, array{employee_name: string, start_page: int, end_page: int}>  $segments
     */
    public function willReturnSegments(array $segments): void
    {
        $this->segments = $segments;
    }

    /**
     * @param  array{employee_first_name: ?string, employee_last_name: ?string, company_name: ?string, document_date: ?string, document_type: ?string, description: ?string, confidence_score: ?int}  $fields
     */
    public function willReturnFields(array $fields): void
    {
        $this->fields = $fields;
        $this->extractException = null;
    }

    public function willThrowOnExtract(\Throwable $exception): void
    {
        $this->extractException = $exception;
    }

    public function splitDocument(string $ocrText, int $pageCount, string $boundaryNonce): array
    {
        return $this->segments;
    }

    public function extractFields(string $ocrText): array
    {
        if ($this->extractException !== null) {
            throw $this->extractException;
        }

        return $this->fields ?? throw new \LogicException('FakeDocumentAiGateway::willReturnFields() non configurato.');
    }
}
