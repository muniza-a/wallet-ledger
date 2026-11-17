<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\BearerTokenAuth;
use App\Services\LedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Replace the whole global stack: auth only, before routing and before any DB access.
        // No sessions, cookies, CSRF or string trimming on this API.
        $middleware->use([BearerTokenAuth::class]);
        $middleware->group('api', []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every error leaves as {"error": "<code>", "message": "<text>"}.
        $exceptions->render(function (Throwable $e) {
            return match (true) {
                $e instanceof ApiException => $e->render(),
                $e instanceof NotFoundHttpException => ApiException::json(404, 'not_found', 'Route not found.'),
                $e instanceof MethodNotAllowedHttpException => ApiException::json(405, 'method_not_allowed', 'Method not allowed.'),
                $e instanceof HttpExceptionInterface => ApiException::json($e->getStatusCode(), 'http_error', 'Request could not be processed.'),
                $e instanceof QueryException && LedgerService::isRetryable($e) => ApiException::json(503, 'service_unavailable', 'Temporary contention, please retry.'),
                default => ApiException::json(500, 'internal_error', 'Internal server error.'),
            };
        });
    })
    ->create();
