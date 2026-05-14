<?php

use App\Poc\Commands\ResetPocData;
use App\Poc\Exceptions\AiServiceException;
use Illuminate\Foundation\Application;
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
        // Keep this step so Laravel registers the default "web" middleware group.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (AiServiceException $exception, Request $request) {
            if ($request->is('poc/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], $exception->getCode() ?: 502);
            }

            return null;
        });

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
