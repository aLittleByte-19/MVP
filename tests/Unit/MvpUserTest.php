<?php

use App\Mvp\Identity\MvpUser;

/** Utente con i ruoli passati, il resto fisso: qui conta solo l'autorizzazione. */
function mvpUser(array $roles = ['operator']): MvpUser
{
    return new MvpUser('u-1', 'mario.rossi@example.com', 'Mario Rossi', 'mvp-local-tenant', $roles);
}

test('the user exposes the identity used by the framework', function () {
    $user = mvpUser();

    expect($user->getAuthIdentifierName())->toBe('mvp_user_id')
        ->and($user->getAuthIdentifier())->toBe('u-1')
        ->and($user->tenantId)->toBe('mvp-local-tenant')
        ->and($user->email)->toBe('mario.rossi@example.com')
        ->and($user->name)->toBe('Mario Rossi');
});

test('no password or remember token is ever exposed', function () {
    // L'autenticazione e' fuori perimetro: l'identita' arriva da header o
    // configurazione, quindi qui non deve esistere nessuna credenziale.
    $user = mvpUser();

    expect($user->getAuthPassword())->toBe('')
        ->and($user->getAuthPasswordName())->toBe('mvp_password')
        ->and($user->getRememberToken())->toBeNull()
        ->and($user->getRememberTokenName())->toBe('');

    $user->setRememberToken('qualsiasi-cosa');

    expect($user->getRememberToken())->toBeNull();
});

test('hasAnyRole accepts a user holding at least one of the required roles', function () {
    expect(mvpUser(['operator', 'auditor'])->hasAnyRole(['auditor']))->toBeTrue()
        ->and(mvpUser(['operator'])->hasAnyRole(['admin', 'operator']))->toBeTrue();
});

test('hasAnyRole rejects a user holding none of them', function () {
    expect(mvpUser(['viewer'])->hasAnyRole(['admin', 'operator']))->toBeFalse();
});

test('hasAnyRole rejects a user without roles', function () {
    expect(mvpUser([])->hasAnyRole(['operator']))->toBeFalse();
});

test('hasAnyRole rejects an empty requirement instead of letting everyone through', function () {
    // Un elenco di ruoli richiesti vuoto non deve valere come "nessun vincolo":
    // sarebbe un permesso concesso per omissione.
    expect(mvpUser(['operator'])->hasAnyRole([]))->toBeFalse();
});
