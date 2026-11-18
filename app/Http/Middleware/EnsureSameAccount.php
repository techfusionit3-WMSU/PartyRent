<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every tab of a browser shares ONE sign-in. If someone signs in as another account in a second tab, a page
 * opened under the first account must not send anything as the second one (e.g. a rent request "from" the
 * wrong person). Pages stamp the account they were rendered for (`_auth` field / X-Auth-Id header, added by
 * app.js); a write that carries a different account is refused and nothing is saved.
 */
class EnsureSameAccount
{
    /** Routes that are fine from any tab (signing in/out, registration, password reset). */
    private const SKIP = ['login', 'logout', 'register', 'register.*', 'password.*', 'verification.*', 'webhooks.*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && self::mismatch($request) && ! $request->routeIs(...self::SKIP)) {
            return self::refuse($request);
        }

        return $next($request);
    }

    /** True when the page that sent this request was opened for a different account than the one now signed in. */
    public static function mismatch(Request $request): bool
    {
        $sent = $request->header('X-Auth-Id', $request->input('_auth'));
        if ($sent === null || $sent === '') {
            return false;
        }

        return (int) $sent !== (int) ($request->user()?->id ?? 0);
    }

    public static function refuse(Request $request): Response
    {
        $u = $request->user();
        $msg = $u
            ? 'This page was opened for a different account. You are now signed in as '.$u->name.' ('.$u->email.') in this browser, so nothing was sent. Check the account and try again.'
            : 'You were signed out in another tab, so nothing was sent. Please sign in again.';
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'error' => $msg, 'accountChanged' => true], 409);
        }

        return redirect()->to($u ? route($u->homeRoute(session('mode'))) : route('login'))->with('warn', $msg);
    }
}
