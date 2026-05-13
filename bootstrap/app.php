<?php

use Illuminate\Foundation\Application;
use App\Poc\Commands\ResetPocData;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ResetPocData::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenMismatchException $exception, Request $request) {
            if ($request->is('poc/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => "La pagina è rimasta aperta troppo a lungo. Ricaricala e riprova l'operazione.",
                ], 419);
            }

            return null;
        });

        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($exception->getStatusCode() === 419 && ($request->is('poc/*') || $request->expectsJson())) {
                return response()->json([
                    'message' => "La pagina è rimasta aperta troppo a lungo. Ricaricala e riprova l'operazione.",
                ], 419);
            }

            return null;
        });
    })->create();
