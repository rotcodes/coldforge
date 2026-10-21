<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
        // One-click unsubscribe (RFC 8058) is a POST from the mailbox provider, no CSRF token possible.
        // Brevo webhooks are also unauthenticated POSTs — auth is the long secret in the URL path.
        $middleware->validateCsrfTokens(except: ['u/*', 'webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
