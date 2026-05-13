<?php

namespace App\Poc\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Command for resetting POC processing data.
 */
class ResetPocData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'poc:reset-data {--force : Run without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset generated PoC processing data from the database and document storage.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Reset all generated PoC processing data?')) {
            $this->components->info('Reset skipped.');

            return self::SUCCESS;
        }

        $tables = array_values(array_filter([
            'extracted_data',
            'sub_documents',
            'original_documents',
            'communications',
        ], fn (string $table): bool => Schema::hasTable($table)));

        $this->resetTables($tables);
        $this->resetStorage();

        $this->components->info('Generated PoC processing data has been reset.');

        return self::SUCCESS;
    }

    /**
     * Reset the specified database tables.
     *
     * @param  array<int, string>  $tables
     * @return void
     */
    private function resetTables(array $tables): void
    {
        if ($tables === []) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $grammar = DB::connection()->getQueryGrammar();
            $tableList = collect($tables)
                ->map(fn (string $table): string => $grammar->wrapTable($table))
                ->implode(', ');

            DB::statement("TRUNCATE TABLE {$tableList} RESTART IDENTITY CASCADE");

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            try {
                foreach ($tables as $table) {
                    DB::table($table)->truncate();
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }

            return;
        }

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                DB::table($table)->delete();
            }

            if ($driver === 'sqlite' && Schema::hasTable('sqlite_sequence')) {
                DB::table('sqlite_sequence')->whereIn('name', $tables)->delete();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * Reset the document storage directories.
     *
     * @return void
     */
    private function resetStorage(): void
    {
        $documentDisk = config('filesystems.default', 'local');

        Storage::disk($documentDisk)->deleteDirectory('documents');
        Storage::disk($documentDisk)->deleteDirectory('livewire-tmp');

        Storage::disk('local')->deleteDirectory('documents');
        Storage::disk('local')->deleteDirectory('livewire-tmp');
        Storage::disk('public')->deleteDirectory('documents');

        File::deleteDirectory(storage_path('app/tmp/poc-processing'));
        File::deleteDirectory(base_path('documenti_ocr'));
    }
}
