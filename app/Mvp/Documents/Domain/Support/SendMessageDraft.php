<?php

namespace App\Mvp\Documents\Domain\Support;

/**
 * Il messaggio precompilato che accompagna un sotto-documento: destinatario,
 * oggetto e corpo calcolati dai dati estratti (UC-51).
 *
 * Logica pura, senza dipendenze: la stessa bozza serve a chi la mostra
 * (`MvpStateService`, per l'anteprima nel pannello) e a chi la stampa
 * (`SendMessageService`, per il PDF). Erano due copie separate delle stesse
 * regole, e correggere l'oggetto in una sola avrebbe fatto promettere
 * all'anteprima un documento diverso da quello consegnato.
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
     * L'oggetto dice che cosa si riceve e a quando si riferisce.
     *
     * Diceva «Invio documento, cedolino paga»: nominava l'azione del mittente,
     * che nella MVP non avviene (si scarica un PDF, non si spedisce nulla), e
     * ripeteva su ogni documento della stessa persona una riga identica, che
     * fra i propri file non si distingue da quella del mese prima. Il periodo
     * di riferimento e' quello dichiarato al caricamento, autoritativo per
     * UC-32 e per un cedolino il dato che lo identifica; in sua assenza vale la
     * data del documento, e poi l'azienda.
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
