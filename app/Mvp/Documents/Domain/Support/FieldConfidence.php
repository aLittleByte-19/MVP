<?php

namespace App\Mvp\Documents\Domain\Support;

/**
 * Attribuisce a un campo estratto la confidenza OCR del testo da cui proviene.
 *
 * La media di pagina non serve allo scopo: una pagina di cedolino e' quasi
 * tutta contorno — intestazioni di tabella, etichette, note — che l'OCR legge
 * quasi perfettamente, mentre i campi che contano sono poche righe. Mediando,
 * il contorno domina, e un cognome illeggibile in mezzo a una pagina nitida
 * non abbassa il punteggio abbastanza da mandare il documento in revisione.
 *
 * Qui il valore restituito dal modello viene invece cercato fra le righe OCR:
 * la confidenza del campo e' quella della riga che lo contiene. Il fatto stesso
 * che il valore non si trovi e' un'informazione: o e' un campo derivato, che il
 * modello normalizza invece di trascrivere, oppure non compare nel documento.
 *
 * Logica pura, senza dipendenze: si prova senza Textract e senza database
 * (vedi ADR 0013).
 */
final class FieldConfidence
{
    /**
     * Lunghezza minima di un token perche' valga come ancora nella ricerca.
     * Sotto i due caratteri si aggancerebbe a qualunque riga.
     */
    private const MIN_TOKEN_LENGTH = 2;

    /**
     * Cerca il valore fra le righe OCR e ne restituisce la confidenza.
     *
     * Prima prova la corrispondenza piena: se il valore compare per intero in
     * una riga, quella riga e' la sua fonte e ne porta la confidenza. Se non
     * compare per intero — succede quando il valore va a capo, per esempio una
     * ragione sociale lunga — si ripiega sui singoli token e si prende il piu'
     * debole, perche' un campo vale quanto la sua parte meno leggibile.
     *
     * @param  array<int, array{text: string, confidence: float|null}>  $blocks
     * @return float|null la confidenza del campo, oppure null se il valore non
     *                    e' rintracciabile fra le righe
     */
    public static function forValue(?string $value, array $blocks): ?float
    {
        $needle = self::normalize((string) $value);

        if ($needle === '' || $blocks === []) {
            return null;
        }

        $whole = self::bestMatch($needle, $blocks);

        if ($whole !== null) {
            return $whole;
        }

        $tokens = array_values(array_filter(
            explode(' ', $needle),
            static fn (string $token): bool => mb_strlen($token) >= self::MIN_TOKEN_LENGTH,
        ));

        if ($tokens === []) {
            return null;
        }

        $weakest = null;

        foreach ($tokens as $token) {
            $match = self::bestMatch($token, $blocks);

            // Un solo token introvabile rende il campo non rintracciabile: non
            // si puo' dire da dove venga un valore che il documento non porta.
            if ($match === null) {
                return null;
            }

            $weakest = $weakest === null ? $match : min($weakest, $match);
        }

        return $weakest;
    }

    /**
     * Confidenza della riga piu' leggibile fra quelle che contengono l'ago.
     *
     * Si prende la migliore e non la peggiore perche' un valore che compare
     * anche una sola volta in chiaro e' stato letto bene almeno li': le altre
     * occorrenze non lo rendono meno affidabile.
     *
     * @param  array<int, array{text: string, confidence: float|null}>  $blocks
     */
    private static function bestMatch(string $needle, array $blocks): ?float
    {
        $best = null;

        foreach ($blocks as $block) {
            $confidence = $block['confidence'] ?? null;

            if ($confidence === null) {
                continue;
            }

            if (! str_contains(self::normalize((string) ($block['text'] ?? '')), $needle)) {
                continue;
            }

            $confidence = (float) $confidence;
            $best = $best === null ? $confidence : max($best, $confidence);
        }

        return $best;
    }

    /**
     * Riduce testo OCR e valore estratto a una forma confrontabile.
     *
     * Maiuscole, accenti sciolti nella lettera base e punteggiatura rimossa:
     * "Meridiana Logistica S.p.A." e "MERIDIANA LOGISTICA S P A" devono
     * corrispondere, perche' la differenza sta nella resa, non nel dato.
     */
    private static function normalize(string $value): string
    {
        $value = mb_strtoupper(trim($value));

        $accents = [
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ];

        $value = strtr($value, $accents);
        $value = preg_replace('/[^A-Z0-9]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * Rese alternative di una data, per cercarla come la scrive il documento.
     *
     * Il modello restituisce sempre YYYY-MM-DD, ma sul foglio la stessa data
     * e' scritta in italiano: senza queste varianti il campo risulterebbe
     * sempre non rintracciabile, e ricadrebbe sulla media di pagina.
     *
     * @return list<string>
     */
    public static function dateVariants(string $isoDate): array
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $isoDate, $m) !== 1) {
            return [$isoDate];
        }

        [, $year, $month, $day] = $m;
        $mesi = [
            '01' => 'gennaio', '02' => 'febbraio', '03' => 'marzo', '04' => 'aprile',
            '05' => 'maggio', '06' => 'giugno', '07' => 'luglio', '08' => 'agosto',
            '09' => 'settembre', '10' => 'ottobre', '11' => 'novembre', '12' => 'dicembre',
        ];
        $mese = $mesi[$month] ?? $month;
        $giorno = ltrim($day, '0');

        return [
            $isoDate,
            "{$day}/{$month}/{$year}",
            "{$day}-{$month}-{$year}",
            "{$day}.{$month}.{$year}",
            "{$giorno} {$mese} {$year}",
            "{$mese} {$year}",
            "{$month}/{$year}",
        ];
    }

    /**
     * Confidenza di una data, provandone le rese piu' comuni.
     *
     * @param  array<int, array{text: string, confidence: float|null}>  $blocks
     */
    public static function forDate(?string $isoDate, array $blocks): ?float
    {
        if ($isoDate === null || trim($isoDate) === '') {
            return null;
        }

        foreach (self::dateVariants(trim($isoDate)) as $variant) {
            $confidence = self::forValue($variant, $blocks);

            if ($confidence !== null) {
                return $confidence;
            }
        }

        return null;
    }
}
