<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Interface language: English (en) or Filipino (fil), chosen with the EN / FIL switch and kept in a cookie. */
class SetLocale
{
    public const LOCALES = ['en' => 'English', 'fil' => 'Filipino'];

    public function handle(Request $request, Closure $next): Response
    {
        $l = $request->session()->get('locale', $request->cookie('pr_lang'));
        app()->setLocale(array_key_exists((string) $l, self::LOCALES) ? $l : 'en');

        return $next($request);
    }
}
