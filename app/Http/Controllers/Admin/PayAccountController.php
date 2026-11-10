<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notifier;
use App\Http\Controllers\Controller;
use App\Models\PayAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Superadmin: PartyRent's collection accounts (where customers send transfers), with on/off and monthly caps. */
class PayAccountController extends Controller
{
    public function index()
    {
        return view('super.pay-accounts', ['accounts' => PayAccount::ordered()->get()]);
    }

    private function validated(Request $request): array
    {
        $v = $request->validate([
            'type' => 'required|in:'.implode(',', array_keys(PayAccount::TYPE_LABEL)),
            'label' => 'required|string|max:60',
            'account_name' => 'nullable|string|max:80',
            'account_no' => 'nullable|string|max:40',
            'monthly_cap' => 'nullable|numeric|min:1|max:99999999',
            'sort' => 'nullable|integer|min:0|max:999',
            'qr' => 'nullable|image|max:4096',
        ]);
        if (trim((string) ($v['account_no'] ?? '')) === '' && ! $request->hasFile('qr') && ! ($request->route('payAccount')?->qr_path && ! $request->boolean('remove_qr'))) {
            abort(back()->withInput()->with('error', 'Give the account number, a QR code, or both.'));
        }

        return [
            'type' => $v['type'], 'label' => trim($v['label']), 'account_name' => trim((string) ($v['account_name'] ?? '')),
            'account_no' => trim((string) ($v['account_no'] ?? '')), 'monthly_cap' => ($v['monthly_cap'] ?? null) ?: null, 'sort' => (int) ($v['sort'] ?? 0),
        ];
    }

    private function saveQr(Request $request, PayAccount $a): void
    {
        $old = $a->qr_path;
        if ($request->hasFile('qr')) {
            $a->qr_path = $request->file('qr')->store('pay-accounts', 'local');
        } elseif ($request->boolean('remove_qr')) {
            $a->qr_path = null;
        }
        if ($old && $old !== $a->qr_path) {
            Storage::disk('local')->delete($old);
        }
    }

    public function store(Request $request)
    {
        $a = new PayAccount($this->validated($request) + ['active' => true]);
        $this->saveQr($request, $a);
        $a->save();
        Notifier::audit('Added payment account', $a->display().($a->monthly_cap ? ' · cap '.money($a->monthly_cap).'/month' : ''));

        return back()->with('success', $a->label.' added. Customers can now pay into it.');
    }

    public function update(Request $request, PayAccount $payAccount)
    {
        $before = $payAccount->display().' · cap '.($payAccount->monthly_cap ? money($payAccount->monthly_cap) : 'none');
        $payAccount->fill($this->validated($request));
        $this->saveQr($request, $payAccount);
        $payAccount->save();
        Notifier::audit('Changed payment account', $before.' → '.$payAccount->display().' · cap '.($payAccount->monthly_cap ? money($payAccount->monthly_cap) : 'none'));

        return back()->with('success', $payAccount->label.' saved.');
    }

    public function toggle(PayAccount $payAccount)
    {
        $payAccount->update(['active' => ! $payAccount->active]);
        Notifier::audit($payAccount->active ? 'Turned on payment account' : 'Turned off payment account', $payAccount->display());

        return back()->with('success', $payAccount->label.($payAccount->active ? ' is on. Customers can pay into it again.' : ' is off. Customers no longer see it.'));
    }

    public function destroy(PayAccount $payAccount)
    {
        if ($payAccount->payments()->exists()) {
            return back()->with('error', $payAccount->label.' already has payments recorded against it, so it can only be turned off (past payments keep pointing to it).');
        }
        if ($payAccount->qr_path) {
            Storage::disk('local')->delete($payAccount->qr_path);
        }
        $payAccount->delete();
        Notifier::audit('Removed payment account', $payAccount->display());

        return back()->with('success', $payAccount->label.' removed.');
    }
}
