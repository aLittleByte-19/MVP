<?php

use App\Mvp\Identity\MvpUser;
use App\Mvp\Identity\MvpUserProvider;
use Illuminate\Support\Facades\Auth;

function mvpUserProvider(): MvpUserProvider
{
    return new MvpUserProvider;
}

test('retrieveById fails loudly instead of calling an Eloquent method that does not exist on MvpUser', function () {
    expect(fn () => mvpUserProvider()->retrieveById('u-1'))
        ->toThrow(LogicException::class, "MvpUserProvider::retrieveById() non e' supportato");
});

test('retrieveByToken fails loudly', function () {
    expect(fn () => mvpUserProvider()->retrieveByToken('u-1', 'token'))
        ->toThrow(LogicException::class, "MvpUserProvider::retrieveByToken() non e' supportato");
});

test('retrieveByCredentials fails loudly', function () {
    expect(fn () => mvpUserProvider()->retrieveByCredentials(['email' => 'a@b.test']))
        ->toThrow(LogicException::class, "MvpUserProvider::retrieveByCredentials() non e' supportato");
});

test('validateCredentials fails loudly', function () {
    $user = new MvpUser('u-1', 'mario.rossi@example.com', 'Mario Rossi', 'mvp-local-tenant', ['operator']);

    expect(fn () => mvpUserProvider()->validateCredentials($user, []))
        ->toThrow(LogicException::class, "MvpUserProvider::validateCredentials() non e' supportato");
});

test("the 'mvp' guard resolves its user provider to MvpUserProvider, matching config/auth.php", function () {
    expect(Auth::guard('mvp')->getProvider())->toBeInstanceOf(MvpUserProvider::class);
});
