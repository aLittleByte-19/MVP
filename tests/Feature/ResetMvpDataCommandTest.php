<?php

use App\Models\Communication;
use App\Models\OriginalDocument;
use App\Models\SubDocument;
use Illuminate\Support\Facades\Storage;

/**
 * Il reset e' distruttivo per definizione: quello che va verificato e' che
 * colpisca esattamente i dati generati, e sui dischi giusti. Nessuno storage
 * reale viene toccato, i dischi sono tutti falsi.
 */
beforeEach(function () {
    Storage::fake('s3');
    Storage::fake('local');
    Storage::fake('public');

    config([
        'mvp.documents.storage_disk' => 's3',
        'mvp.communications.cover_disk' => 's3',
        'mvp.communications.cover_prefix' => 'communications/covers',
        'mvp.communications.pdf_disk' => 's3',
        'mvp.communications.pdf_prefix' => 'communications/exports',
    ]);
});

test('without confirmation nothing is deleted', function () {
    OriginalDocument::factory()->create();

    $this->artisan('mvp:reset-data')
        ->expectsConfirmation('Reset all generated MVP processing data?', 'no')
        ->expectsOutputToContain('Reset skipped.')
        ->assertSuccessful();

    expect(OriginalDocument::query()->count())->toBe(1);
});

test('confirming at the prompt performs the reset', function () {
    OriginalDocument::factory()->create();

    $this->artisan('mvp:reset-data')
        ->expectsConfirmation('Reset all generated MVP processing data?', 'yes')
        ->assertSuccessful();

    expect(OriginalDocument::query()->count())->toBe(0);
});

test('the generated tables are emptied', function () {
    $document = OriginalDocument::factory()->create();
    SubDocument::factory()->for($document, 'originalDocument')->create();
    Communication::factory()->create();

    $this->artisan('mvp:reset-data', ['--force' => true])->assertSuccessful();

    expect(OriginalDocument::query()->count())->toBe(0)
        ->and(SubDocument::query()->count())->toBe(0)
        ->and(Communication::query()->count())->toBe(0);
});

test('documents are removed from the disk the services actually write to', function () {
    // Con MVP_DOCUMENT_DISK=real_s3 i documenti vivono sul bucket reale:
    // leggere filesystems.default qui vorrebbe dire pulire un disco vuoto e
    // lasciare intatti i file veri.
    Storage::disk('s3')->put('originals/1/documento.pdf', 'contenuto');
    Storage::disk('s3')->put('sub/1/estratto.pdf', 'contenuto');
    Storage::disk('s3')->put('documents/vecchio-layout.pdf', 'contenuto');

    $this->artisan('mvp:reset-data', ['--force' => true])->assertSuccessful();

    Storage::disk('s3')->assertDirectoryEmpty('/');
});

test('cover images and materialised pdf exports are both removed', function () {
    // Gli export sono materializzati alla prima anteprima o esportazione:
    // senza questa pulizia resterebbero sul bucket a vita.
    Storage::disk('s3')->put('communications/covers/1.png', 'immagine');
    Storage::disk('s3')->put('communications/exports/1.pdf', 'pdf');

    $this->artisan('mvp:reset-data', ['--force' => true])->assertSuccessful();

    Storage::disk('s3')->assertMissing('communications/covers/1.png');
    Storage::disk('s3')->assertMissing('communications/exports/1.pdf');
});

test('the prefixes are read from configuration, not hardcoded', function () {
    config([
        'mvp.communications.cover_prefix' => 'personalizzato/copertine',
        'mvp.communications.pdf_prefix' => 'personalizzato/export',
    ]);
    Storage::disk('s3')->put('personalizzato/copertine/1.png', 'immagine');
    Storage::disk('s3')->put('personalizzato/export/1.pdf', 'pdf');

    $this->artisan('mvp:reset-data', ['--force' => true])->assertSuccessful();

    Storage::disk('s3')->assertMissing('personalizzato/copertine/1.png');
    Storage::disk('s3')->assertMissing('personalizzato/export/1.pdf');
});

test('leftovers from the local and public disks are cleaned too', function () {
    Storage::disk('local')->put('documents/residuo.pdf', 'contenuto');
    Storage::disk('local')->put('livewire-tmp/caricamento.tmp', 'contenuto');
    Storage::disk('public')->put('documents/residuo.pdf', 'contenuto');

    $this->artisan('mvp:reset-data', ['--force' => true])->assertSuccessful();

    Storage::disk('local')->assertMissing('documents/residuo.pdf');
    Storage::disk('local')->assertMissing('livewire-tmp/caricamento.tmp');
    Storage::disk('public')->assertMissing('documents/residuo.pdf');
});

test('the command succeeds on an already empty installation', function () {
    $this->artisan('mvp:reset-data', ['--force' => true])
        ->expectsOutputToContain('Generated MVP processing data has been reset.')
        ->assertSuccessful();
});
