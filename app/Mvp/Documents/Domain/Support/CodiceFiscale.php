<?php

namespace App\Mvp\Documents\Domain\Support;

/**
 * Controllo di validità formale (checksum) del codice fiscale italiano —
 * UC-46/UC-69: un valore che rispetta la lunghezza ma non il checksum va
 * comunque respinto, non solo un controllo di lunghezza. Logica pura,
 * separata dalla regola di validazione in App\Mvp\Documents\Rules (che resta
 * il solo pezzo che dipende dal contratto di validazione di Laravel).
 */
final class CodiceFiscale
{
    private const ODD_VALUES = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9,
        '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9,
        'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
        'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11,
        'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
        'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
    ];

    private const EVEN_VALUES = [
        '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4,
        '5' => 5, '6' => 6, '7' => 7, '8' => 8, '9' => 9,
        'A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, 'E' => 4,
        'F' => 5, 'G' => 6, 'H' => 7, 'I' => 8, 'J' => 9,
        'K' => 10, 'L' => 11, 'M' => 12, 'N' => 13, 'O' => 14,
        'P' => 15, 'Q' => 16, 'R' => 17, 'S' => 18, 'T' => 19,
        'U' => 20, 'V' => 21, 'W' => 22, 'X' => 23, 'Y' => 24, 'Z' => 25,
    ];

    public static function isValid(string $value): bool
    {
        $code = strtoupper($value);

        if (! preg_match('/^[A-Z0-9]{16}$/', $code)) {
            return false;
        }

        $sum = 0;

        for ($position = 0; $position < 15; $position++) {
            $char = $code[$position];
            $sum += $position % 2 === 0 ? self::ODD_VALUES[$char] : self::EVEN_VALUES[$char];
        }

        $expectedCheckChar = chr(65 + ($sum % 26));

        return $code[15] === $expectedCheckChar;
    }
}
