<?php

namespace App\Mvp\Communications\Domain\Ports\Outbound;

/**
 * Porta secondaria verso lo storage delle immagini di copertina. Nessun
 * riferimento a Flysystem/Storage.
 */
interface CommunicationCoverStoragePort
{
    /**
     * @throws \RuntimeException se il salvataggio fallisce.
     */
    public function store(string $path, string $bytes): void;

    /**
     * Best-effort: un oggetto orfano non deve far fallire l'operazione
     * dell'operatore, l'adapter registra e prosegue (vedi CommunicationCoverService originale).
     */
    public function delete(string $path): void;

    /**
     * @throws \RuntimeException se lo storage non e' raggiungibile (guasto
     *                           infrastrutturale, da distinguere da "file assente").
     */
    public function exists(string $path): bool;

    /**
     * @throws \RuntimeException se il file non esiste.
     */
    public function read(string $path): string;
}
