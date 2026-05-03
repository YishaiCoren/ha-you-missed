<?php

use App\Support\MakerLoftErrorReporter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // DO App Platform terminates TLS at its load balancer and forwards
        // to the app over HTTP with X-Forwarded-* headers. Trusting any
        // upstream makes url()/route() generate HTTPS correctly and lets
        // secure cookies work end-to-end.
        $middleware->trustProxies(at: '*');

        // Resolve trusted hosts from APP_URL. Falls back to 'localhost' if
        // APP_URL isn't parseable — never an empty array, which Laravel
        // treats as "trust all hosts."
        $middleware->trustHosts(at: function () {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return $host ? [$host] : ['localhost'];
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Laravel's default internalDontReport list includes HttpException,
        // so abort(500) and other 5xx HTTP aborts never fire any custom
        // report callback. Removing it restores reachability; we then
        // filter inside the callback so 4xx (404 / 401 / 403 / …) stay
        // silent (return false halts the report chain, including default
        // logging) while 5xx flow through to the MakerLoft dashboard
        // and the default logger (so the orchestrator's docker-logs tail
        // can also pick them up).
        $exceptions->stopIgnoring([HttpException::class]);

        // Forward every unhandled exception to the MakerLoft dashboard's
        // error-ingest webhook. No-ops silently when the env vars are
        // unset (e.g. local dev or a fork deployed outside MakerLoft's
        // orchestration), so this is safe to ship in every starter.
        $exceptions->report(function (Throwable $e) {
            if ($e instanceof HttpException && $e->getStatusCode() < 500) {
                return false;
            }

            MakerLoftErrorReporter::report($e);
        });
    })->create();
