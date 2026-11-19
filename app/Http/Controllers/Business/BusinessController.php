<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\EventBooking;
use App\Models\Supplier;
use Illuminate\Http\Request;

/** Shared helpers for the business (event company) workspace. Routes are guarded by EnsureRole (business shops only). */
abstract class BusinessController extends Controller
{
    protected function biz(Request $request): Supplier
    {
        $s = $request->user()->shop();
        abort_unless($s && $s->isOrganization(), 403);

        return $s;
    }

    protected function own(Request $request, EventBooking $b): EventBooking
    {
        abort_unless($b->supplier_id === $this->biz($request)->id, 404);

        return $b;
    }

    protected function done(array $res, string $ok)
    {
        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $ok : $res['error']);
    }
}
