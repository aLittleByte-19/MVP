<?php

use App\Console\Commands\ConsumeWorkflowTasks;
use App\Console\Commands\ListDlqMessages;
use App\Console\Commands\ResetMvpData;
use App\Copilot\Support\RuntimeConfigurationLoader;
use App\Exceptions\Copilot\AiServiceException;
use App\Http\Middleware\AuthorizeMvpAccess;
use App\Http\Middleware\CorrelateRequests;
use App\Http\Middleware\RecordHttpMetrics;
use App\Http\Middleware\ResolveMvpIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

RuntimeConfigurationLoader::load();

$app = new Application(dirname(__DIR__));

// Con CONFIG_SOURCE=aws la configurazione arriva da SSM/Secrets Manager (loader
// sopra) o dall'ambiente del container: puntare dotenv su /dev/null garantisce
// che un eventuale .env presente nel filesystem non possa sovrascriverla.
// Laravel non espone un'API per disabilitare dotenv, da cui questo idioma.
$app->useEnvironmentPath('/dev');
$app->loadEnvironmentFrom('null');

return (new ApplicationBuilder($app))
    ->withKernels()
    ->withEvents()
    ->withCommands()
    ->withProviders()
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        ConsumeWorkflowTasks::class,
        ListDlqMessages::class,
        ResetMvpData::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // The app sits behind Traefik (TLS termination) and Nginx; trust forwarded
        // headers so HTTPS scheme detection, generated URLs (e.g. SSE streamUrl) and
        // secure cookies work correctly.
        $middleware->trustProxies(at: '*');
        $middleware->append(CorrelateRequests::class);
        $middleware->append(RecordHttpMetrics::class);
        $middleware->alias([
            'mvp.identity' => ResolveMvpIdentity::class,
            'mvp.authorize' => AuthorizeMvpAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $expectsApiJson = static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $jsonError = static function (Request $request, string $code, string $message, int $status, array $extra = []) {
            return response()->json([
                'error' => array_merge([
                    'code' => $code,
                    'message' => $message,
                    'requestId' => $request->attributes->get('request_id'),
                    'correlationId' => $request->attributes->get('correlation_id'),
                ], $extra),
            ], $status);
        };

        $exceptions->render(function (AiServiceException $exception, Request $request) use ($expectsApiJson, $jsonError) {
            if ($expectsApiJson($request)) {
                return $jsonError($request, 'upstream_unavailable', $exception->getMessage(), $exception->getCode() ?: 502);
            }

            return null;
        });

        $exceptions->render(function (TokenMismatchException $exception, Request $request) use ($expectsApiJson, $jsonError) {
            if ($expectsApiJson($request)) {
                return $jsonError($request, 'csrf_token_mismatch', "La pagina è rimasta aperta troppo a lungo. Ricaricala e riprova l'operazione.", 419);
            }

            return null;
        });

        $exceptions->render(function (ValidationException $exception, Request $request) use ($expectsApiJson, $jsonError) {
            if ($expectsApiJson($request)) {
                return $jsonError($request, 'validation_failed', 'I dati inviati non sono validi.', 422, [
                    'fields' => $exception->errors(),
                ]);
            }

            return null;
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) use ($expectsApiJson, $jsonError) {
            if ($expectsApiJson($request)) {
                return $jsonError($request, 'unauthorized', 'Autenticazione richiesta.', 401);
            }

            return null;
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) use ($expectsApiJson, $jsonError) {
            if ($expectsApiJson($request)) {
                return $jsonError($request, 'forbidden', 'Operazione non autorizzata.', 403);
            }

            return null;
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) use ($expectsApiJson, $jsonError) {
            if ($expectsApiJson($request)) {
                return $jsonError($request, 'not_found', 'Risorsa non trovata.', 404);
            }

            return null;
        });

        $exceptions->render(function (HttpException $exception, Request $request) use ($expectsApiJson, $jsonError) {
            if ($expectsApiJson($request)) {
                $status = $exception->getStatusCode();

                return $jsonError(
                    $request,
                    match ($status) {
                        401 => 'unauthorized',
                        403 => 'forbidden',
                        404 => 'not_found',
                        409 => 'conflict',
                        419 => 'csrf_token_mismatch',
                        default => $status >= 500 ? 'server_error' : 'http_error',
                    },
                    $status >= 500 ? 'Errore interno del server.' : ($exception->getMessage() ?: 'Richiesta non valida.'),
                    $status,
                );
            }

            return null;
        });
    })->create();
