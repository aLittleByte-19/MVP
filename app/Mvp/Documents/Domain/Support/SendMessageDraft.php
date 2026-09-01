<?php

namespace App\Mvp\Documents\Domain\Support;

/**
 * Il messaggio precompilato che accompagna un sotto-documento: destinatario,
 * oggetto e corpo calcolati dai dati estratti (UC-51).
 *
 * Logica pura, senza dipendenze: la stessa bozza serve sia all'anteprima
 * (`MvpStateService`) sia alla stampa (`SendMessageService`), cosi' le due
 * non rischiano di promettere un oggetto diverso da quello poi consegnato.
 */
final class SendMessageDraft
{
    private const MONTHS = [
        1 => 'gennaio', 2 => 'febbraio', 3 => 'marzo', 4 => 'aprile',
        5 => 'maggio', 6 => 'giugno', 7 => 'luglio', 8 => 'agosto',
        9 => 'settembre', 10 => 'ottobre', 11 => 'novembre', 12 => 'dicembre',
    ];

    public static function recipient(string $employeeName): string
    {
        return $employeeName !== '' ? $employeeName : 'Destinatario non disponibile';
    }

    /**
     * L'oggetto dice che cosa si riceve e a quando si riferisce, cosi' due
     * documenti della stessa persona non si distinguono con una riga
     * identica fra i propri file. Il periodo di riferimento e' quello
     * dichiarato al caricamento (autoritativo per UC-32); in sua assenza
     * vale la data del documento, poi l'azienda.
     */
    public static function subject(
        ?string $documentType,
        ?string $companyName,
        ?string $documentDateDisplay,
        ?int $referenceMonth = null,
        ?int $referenceYear = null,
    ): string {
        $label = self::label($documentType);
        $period = self::period($referenceMonth, $referenceYear);

        if ($period !== null) {
            return "{$label} di {$period}";
        }

        if ($documentDateDisplay) {
            return "{$label} del {$documentDateDisplay}";
        }

        if ($companyName) {
            return "{$label}, {$companyName}";
        }

        return $label;
    }

    public static function body(
        string $employeeName,
        ?string $documentType,
        ?string $companyName,
        ?string $documentDateDisplay,
        ?string $description,
    ): string {
        $greeting = $employeeName !== '' ? "Gentile {$employeeName}," : 'Gentile destinatario,';
        $documentLabel = $documentType ?: 'documento';
        $reference = "in allegato trova il documento \"{$documentLabel}\"";

        if ($companyName) {
            $reference .= " relativo a {$companyName}";
        }

        if ($documentDateDisplay) {
            $reference .= " del {$documentDateDisplay}";
        }

        $reference .= '.';

        $lines = [$greeting, '', $reference];

        if ($description) {
            $lines[] = '';
            $lines[] = $description;
        }

        $lines[] = '';
        $lines[] = 'Cordiali saluti.';

        return implode("\n", $lines);
    }

    /** La tipologia arriva dal modello in minuscolo: in un oggetto ci va la maiuscola. */
    private static function label(?string $documentType): string
    {
        $type = trim((string) $documentType);

        if ($type === '') {
            return 'Documento in allegato';
        }

        return mb_strtoupper(mb_substr($type, 0, 1)).mb_substr($type, 1);
    }

    /** «giugno 2026», o il solo anno quando il mese non e' stato dichiarato. */
    private static function period(?int $month, ?int $year): ?string
    {
        if ($year === null) {
            return null;
        }

        return isset(self::MONTHS[$month]) ? self::MONTHS[$month].' '.$year : (string) $year;
    }
}
