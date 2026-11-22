<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interface language. The EN / FIL switch was removed (2026-11-22): the app is English only, also for anyone who
 * had picked Filipino before. lang/fil.json is kept so the switch can come back.
 */
class SetLocale
{
    public const LOCALES = ['en' => 'English'];

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('en');

        return $next($request);
    }
}
