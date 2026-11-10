<?php

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
        $middleware->alias(['role' => \App\Http\Middleware\EnsureRole::class]);
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route(auth()->user()->homeRoute(session('mode'))));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Stale CSRF token (419 "Page Expired") — e.g. a login form left open in another tab, or one
        // restored via the Back button after logging out. Send the user back to a freshly-rendered page
        // (with a new token) instead of a dead-end error screen.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }
            $fallback = $request->user() ? route($request->user()->homeRoute(session('mode'))) : route('login');

            return redirect()->back(302, [], $fallback)
                ->withInput($request->except(['_token', 'password', 'password_confirmation', 'current_password']))
                ->with('error', 'Your session had expired, so the page was refreshed. Please try again.');
        });
    })->create();
