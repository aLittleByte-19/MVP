<?php

namespace App\Mvp\Documents\Rules;

use App\Mvp\Documents\Domain\Support\CodiceFiscale;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Adapter Laravel per {@see CodiceFiscale}: il checksum vero e proprio è
 * logica di dominio pura, questa classe traduce solo il risultato nella
 * forma che la validazione Laravel si aspetta.
 */
class ValidCodiceFiscale implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CodiceFiscale::isValid((string) $value)) {
            $fail('Il codice fiscale non supera il controllo di validità formale.');
        }
    }
}
