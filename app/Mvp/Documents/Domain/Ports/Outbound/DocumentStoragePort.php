<?php

namespace App\Mvp\Documents\Domain\Ports\Outbound;

/**
 * Porta secondaria verso lo storage dei file documentali (originali e split).
 * Nessun riferimento a Flysystem/Storage: l'adapter decide il disco concreto.
 */
interface DocumentStoragePort
{
    /**
     * Salva sul disco documenti il file gia' presente a un percorso assoluto
     * del filesystem locale (es. il file temporaneo di un upload) e
     * restituisce il percorso relativo al disco documenti. Un percorso
     * assoluto e' un concetto del filesystem, non del framework: l'adapter
     * primario (Controller) lo ricava da `UploadedFile::getRealPath()` senza
     * che questa interfaccia debba conoscere quel tipo.
     *
     * @throws \RuntimeException se il salvataggio fallisce.
     */
    public function storeFromLocalPath(string $absoluteSourcePath, string $directory): string;

    /**
     * Legge il contenuto di un file dal disco documenti.
     *
     * @throws \RuntimeException se il file non esiste.
     */
    public function read(string $path): string;

    /**
     * Verifica l'esistenza di un file sul disco documenti.
     *
     * @throws \RuntimeException se lo storage non e' raggiungibile (guasto
     *                           infrastrutturale, da distinguere da "file assente").
     */
    public function exists(string $path): bool;

    /**
     * Scrive contenuto binario a un percorso del disco documenti.
     */
    public function write(string $path, string $contents): void;

    public function delete(string $path): void;
}
