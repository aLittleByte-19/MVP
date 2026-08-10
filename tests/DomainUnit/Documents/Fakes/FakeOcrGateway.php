<?php

namespace Tests\DomainUnit\Documents\Fakes;

use App\Mvp\Documents\Domain\Ports\Outbound\OcrGatewayPort;

final class FakeOcrGateway implements OcrGatewayPort
{
    /** @var array{enabled: bool, jobId: ?string, text: ?string, pages: array<int, array{page: int, text: string, confidenceAvg: float|null}>, confidenceAvg: ?float} */
    private array $result = ['enabled' => false, 'jobId' => null, 'text' => null, 'pages' => [], 'confidenceAvg' => null];

    /**
     * @param  array{enabled: bool, jobId: ?string, text: ?string, pages: array<int, array{page: int, text: string, confidenceAvg: float|null}>, confidenceAvg: ?float}  $result
     */
    public function willReturn(array $result): void
    {
        $this->result = $result;
    }

    public function detectText(string $bucket, string $key, string $idempotencyKey): array
    {
        return $this->result;
    }
}
