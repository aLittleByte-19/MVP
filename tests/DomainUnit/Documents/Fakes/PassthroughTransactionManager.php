<?php

namespace Tests\DomainUnit\Documents\Fakes;

use App\Mvp\Support\Persistence\TransactionManagerPort;

final class PassthroughTransactionManager implements TransactionManagerPort
{
    public function run(callable $operation): mixed
    {
        return $operation();
    }
}
