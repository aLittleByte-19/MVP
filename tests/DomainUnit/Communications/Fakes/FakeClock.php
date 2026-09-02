<?php

namespace Tests\DomainUnit\Communications\Fakes;

use Psr\Clock\ClockInterface;

final class FakeClock implements ClockInterface
{
    public function __construct(private readonly \DateTimeImmutable $now) {}

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
