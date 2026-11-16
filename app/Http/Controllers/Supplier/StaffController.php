<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Notifier;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\StartSellingController;
use Illuminate\Http\Request;

/** Business documents upload for an approved supplier (team accounts live in TeamController). */
class StaffController extends Controller
{
    /** Personal → Business upgrade (or re-sending documents after a rejection). */
    public function business(Request $request)
    {
        $s = $request->user()->shop();
        $request->merge(['account_type' => 'business']);
        $s->update(StartSellingController::business($request, $s));
        Notifier::notify('admin', 'admin', 'Business documents to check', $s->name.' wants to become a Business account.', route('admin.applications', ['tab' => 'business'], false));
        Notifier::audit('Requested business account', $s->name);

        return back()->with('success', 'Documents sent. PartyRent will check them, usually within 1–2 business days.');
    }
}
