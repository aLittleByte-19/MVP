<?php

namespace App\Mvp\Support\Persistence;

use Illuminate\Support\Facades\DB;

final class LaravelTransactionManager implements TransactionManagerPort
{
    public function run(callable $operation): mixed
    {
        return DB::transaction($operation);
    }
}
