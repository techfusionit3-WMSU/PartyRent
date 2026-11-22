<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The inventory memo (products, shops, discounts, stock) lives for one request. A live server starts every request
 * fresh anyway; this makes it true where the app is kept between requests too (tests, long-running workers),
 * so a request never sees what an earlier one remembered.
 */
class FreshRequestCaches
{
    public function handle(Request $request, Closure $next): Response
    {
        inv()->flush();

        return $next($request);
    }
}
