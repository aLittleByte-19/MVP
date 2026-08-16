<?php

use App\Mvp\Documents\Domain\Support\CodiceFiscale;

/**
 * Test di dominio puro (nessun bootstrap Laravel): dimostra che il checksum
 * e' usabile senza passare da Illuminate\Contracts\Validation\ValidationRule
 * (vedi tests/Unit/ValidCodiceFiscaleTest.php per la copertura esaustiva
 * attraverso l'adapter Laravel, che delega qui).
 */
test('isValid accepts a code with the correct check character', function () {
    expect(CodiceFiscale::isValid('RSSMRA85M01H501Q'))->toBeTrue();
});

test('isValid rejects a code with the wrong check character', function () {
    expect(CodiceFiscale::isValid('RSSMRA85M01H501Z'))->toBeFalse();
});

test('isValid rejects malformed input', function () {
    expect(CodiceFiscale::isValid('troppo corto'))->toBeFalse();
});
