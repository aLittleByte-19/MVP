<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ResetMvpData extends Command
{
    /** @var string */
    protected $signature = 'mvp:reset-data {--force : Run without confirmation}';

    /** @var string */
    protected $description = 'Reset generated MVP processing data from the database and document storage.';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Reset all generated MVP processing data?')) {
            $this->components->info('Reset skipped.');

            return self::SUCCESS;
        }

        $tables = array_values(array_filter([
            'extracted_data',
            'sub_documents',
            'original_documents',
            'communications',
            // Relazione morph senza foreign key: va svuotata esplicitamente.
            'workflow_tasks',
        ], fn (string $table): bool => Schema::hasTable($table)));

        $this->resetTables($tables);
        $this->resetStorage();

        $this->components->info('Generated MVP processing data has been reset.');

        return self::SUCCESS;
    }

    /**
     * Reset tables using the safest available strategy for the active database driver.
     *
     * @param  array<int, string>  $tables
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

    private function resetStorage(): void
    {
        // Stesso disco su cui scrivono i servizi documentali: con
        // MVP_DOCUMENT_DISK=real_s3 i documenti vivono sul bucket AWS reale, non
        // su quello LocalStack. Leggere `filesystems.default` qui significava
        // ripulire un disco vuoto e lasciare intatti i file veri.
        $documentDisk = (string) config('mvp.documents.storage_disk', config('filesystems.default', 'local'));

        // I documenti sono salvati in "originals/" e "sub/" relativi al root del
        // disk (vedi ProcessDocumentService); "documents/" resta per pulire
        // eventuali dati pregressi del vecchio layout.
        Storage::disk($documentDisk)->deleteDirectory('originals');
        Storage::disk($documentDisk)->deleteDirectory('sub');
        Storage::disk($documentDisk)->deleteDirectory('documents');
        Storage::disk($documentDisk)->deleteDirectory('livewire-tmp');

        // Copertine ed export PDF hanno prefisso e disco propri e vanno puliti
        // entrambi: gli export sono materializzati alla prima richiesta di
        // anteprima o esportazione e altrimenti resterebbero a vita.
        Storage::disk((string) config('mvp.communications.cover_disk', $documentDisk))
            ->deleteDirectory((string) config('mvp.communications.cover_prefix', 'communications/covers'));

        Storage::disk((string) config('mvp.communications.pdf_disk', $documentDisk))
            ->deleteDirectory((string) config('mvp.communications.pdf_prefix', 'communications/exports'));

        Storage::disk('local')->deleteDirectory('documents');
        Storage::disk('local')->deleteDirectory('livewire-tmp');
        Storage::disk('public')->deleteDirectory('documents');

        File::deleteDirectory(storage_path('app/tmp/mvp-processing'));
        File::deleteDirectory(base_path('documenti_ocr'));
    }
}
