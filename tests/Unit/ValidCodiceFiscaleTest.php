<?php

use App\Mvp\Documents\Rules\ValidCodiceFiscale;

/**
 * Il codice fiscale regge l'identificazione del destinatario, che e' l'obiettivo
 * dichiarato dal committente: accettare un codice con checksum sbagliato vuol
 * dire consegnare un cedolino alla persona sbagliata.
 *
 * I codici usati qui hanno il carattere di controllo calcolato a parte con le
 * tabelle ufficiali, non con la regola sotto test.
 */

/** Esegue la regola e restituisce l'eventuale messaggio di errore. */
function mvpValidateCodiceFiscale(mixed $value): ?string
{
    $message = null;

    (new ValidCodiceFiscale)->validate(
        'fiscal_code',
        $value,
        function (string $failure) use (&$message) {
            $message = $failure;
        }
    );

    return $message;
}

test('accepts codes whose check character is correct', function (string $code) {
    expect(mvpValidateCodiceFiscale($code))->toBeNull();
})->with([
    'RSSMRA85M01H501Q',
    'BNCLRA90A41F205I',
    'VRDGPP70T10L219Z',
    'MRTNNA92E45G273A',
]);

test('accepts a valid code written in lowercase', function () {
    expect(mvpValidateCodiceFiscale('rssmra85m01h501q'))->toBeNull();
});

test('rejects a code with the wrong check character', function () {
    // Stesse prime 15 posizioni di un codice valido: cambia solo il check.
    expect(mvpValidateCodiceFiscale('RSSMRA85M01H501Z'))
        ->toBe('Il codice fiscale non supera il controllo di validità formale.');
});

test('rejects malformed codes', function (string $code) {
    expect(mvpValidateCodiceFiscale($code))
        ->toBe('Il codice fiscale non supera il controllo di validità formale.');
})->with([
    'troppo corto' => 'RSSMRA85M01H50',
    'troppo lungo' => 'RSSMRA85M01H501QQ',
    'vuoto' => '',
    'con trattino' => 'RSSMRA85M01H50-Q',
    'con spazio' => 'RSSMRA85M01H50 Q',
    'con accento' => 'RSSMRÀ85M01H501Q',
]);

test('rejects a transposition of two adjacent characters', function () {
    // Le posizioni pari e dispari pesano diversamente proprio perche' il
    // checksum intercetti gli scambi di caratteri, l'errore di battitura tipico.
    expect(mvpValidateCodiceFiscale('RSSMRA85M01H510Q'))
        ->toBe('Il codice fiscale non supera il controllo di validità formale.');
});

test('rejects values that are not strings', function (mixed $value) {
    expect(mvpValidateCodiceFiscale($value))
        ->toBe('Il codice fiscale non supera il controllo di validità formale.');
})->with([
    'null' => null,
    'intero' => 12345,
]);
