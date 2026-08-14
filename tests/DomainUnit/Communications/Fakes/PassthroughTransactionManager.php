<?php

namespace Tests\DomainUnit\Communications\Fakes;

use App\Mvp\Support\Persistence\TransactionManagerPort;

final class PassthroughTransactionManager implements TransactionManagerPort
{
    public function run(callable $operation): mixed
    {
        return $operation();
    }
}
