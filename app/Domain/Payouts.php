<?php

namespace App\Domain;

use App\Models\Payout;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Escrow and supplier payouts (marketplace model, like Shopee).
 *
 * Customers pay PartyRent, never the supplier directly. Verified payments stay with PartyRent while the
 * rental runs. When the rental is completed, PartyRent releases one payout to the supplier:
 *
 *   income        verified rental/sales payments − rental refunds, split into items + delivery
 *   commission    items × commissionPct (platform setting, frozen on the payout); delivery pays no commission
 *   voucher       PartyRent-funded Free Shipping voucher, paid to the supplier
 *   compensation  damage compensation (paid directly or deducted from the deposit), passed through with no fee
 *   net           items − commission + delivery + voucher + compensation
 *
 * Security deposits are never supplier income: PartyRent holds them and returns them to the customer.
 * Cancelled requests where money was kept (e.g. a late-cancellation charge) are released by an admin
 * once refunds are settled. The admin sends payouts through Xendit (XenditPayouts) or by hand and records the reference.
 */
class Payouts
{
    public static function pct(): float
    {
        return (float) Settings::get('commissionPct', 5);
    }

    /**
     * What a request is worth to its supplier, from verified payments (Shopee style):
     *   items       paid money for items / packages / services — the only part that pays commission
     *   delivery    paid money for the delivery fee — passed through, no commission
     *   voucher     Free Shipping voucher PartyRent gave the customer, paid to the supplier once the rental is completed
     *   commission  items × (commissionPct + Free Shipping program extra, frozen on the request at checkout)
     */
    public static function breakdown(RentalRequest $r, ?float $pct = null): array
    {
        $f = $r->fin();
        $p = $r->pricing ?? [];
        $pct ??= r2(self::pct() + (float) ($p['commissionExtraPct'] ?? 0));
        $income = r2(max(0, $f['paidRental'] - $f['refunded']));
        $delivery = r2(min($income, (float) ($p['deliveryFee'] ?? 0)));
        $items = r2($income - $delivery);
        $voucher = $r->status === 'completed' ? r2((float) ($p['delivery']['voucher'] ?? 0)) : 0.0;
        $comp = r2($f['compPaidDirect'] + $f['depositApplied']);
        $commission = r2($items * $pct / 100);

        return [
            'income' => $income, 'items' => $items, 'delivery' => $delivery, 'voucher' => $voucher,
            'pct' => $pct, 'commission' => $commission, 'compensation' => $comp,
            'net' => r2($items - $commission + $delivery + $voucher + $comp),
        ];
    }

    /** Money PartyRent is holding for this request (shown to the supplier as "in escrow"). */
    public static function held(RentalRequest $r): float
    {
        $b = self::breakdown($r);

        return r2($b['income'] + $b['voucher'] + $b['compensation']);
    }

    public static function nextCode(): string
    {
        return 'PO-'.(10000 + Settings::nextSeq('payout'));
    }

    /** Release the payout for a completed (or cancelled-with-money-kept) request. */
    public static function release(RentalRequest $r): array
    {
        $r->loadMissing('payments');
        if (! in_array($r->status, ['completed', 'cancelled'], true)) {
            return ['ok' => false, 'error' => 'Payouts are released once the rental is completed.'];
        }
        if ($r->status === 'cancelled' && $r->cancel_free) {
            return ['ok' => false, 'error' => $r->code.' was cancelled for free: the customer is refunded in full, nothing goes to the supplier.'];
        }
        if (Payout::where('rental_request_id', $r->id)->exists()) {
            return ['ok' => false, 'error' => 'This request was already released for payout.'];
        }
        if ($r->payments->where('status', 'pending')->isNotEmpty()) {
            return ['ok' => false, 'error' => 'Verify or reject the pending payments for '.$r->code.' first.'];
        }
        $b = self::breakdown($r);
        if ($b['net'] <= 0) {
            return ['ok' => false, 'error' => 'There is nothing to pay out for '.$r->code.'.'];
        }
        $p = DB::transaction(fn () => Payout::create([
            'code' => self::nextCode(), 'supplier_id' => $r->supplier_id, 'rental_request_id' => $r->id,
            'income' => $b['income'], 'commission_pct' => $b['pct'], 'commission' => $b['commission'], 'delivery' => $b['delivery'], 'voucher' => $b['voucher'], 'compensation' => $b['compensation'], 'net' => $b['net'],
            'status' => 'pending',
        ]));
        Notifier::notify($r->supplier->owner_id, 'supplier', 'Payout released', money($p->net).' for '.$r->code.' is ready and will be sent to your payout account ('.$p->code.').', route('supplier.payouts', [], false));
        Notifier::notify('admin', 'admin', 'Payout to send', $r->supplier->name.' · '.money($p->net).' for '.$r->code.' ('.$p->code.').', route('admin.payouts', [], false));
        Notifier::audit('Released payout', $p->code.' · '.$r->code.' · '.money($p->net).' net ('.money($p->commission).' commission)');
        XenditPayouts::autoSend($p); // automatic payouts (superadmin setting), like Shopee

        return ['ok' => true, 'payout' => $p];
    }

    /** Owner/admin sent the money: record the transfer reference. */
    public static function markPaid(Payout $p, array $data, User $by): array
    {
        if ($p->isPaid()) {
            return ['ok' => false, 'error' => $p->code.' is already paid.'];
        }
        if ($p->status === 'sending') {
            return ['ok' => false, 'error' => $p->code.' is being sent through Xendit. Use "Check status" instead.'];
        }
        if (! $p->supplier->hasPayoutAccount()) {
            Notifier::notify($p->supplier->owner_id, 'supplier', 'Add your payout account', money($p->net).' ('.$p->code.') is ready for you. Add a GCash or bank account so PartyRent can send it.', route('supplier.payouts', [], false));

            return ['ok' => false, 'error' => $p->supplier->name.' has not added a payout account yet. They were reminded to add one.'];
        }
        if (! in_array($data['method'] ?? '', array_keys(Payout::METHOD_LABEL), true)) {
            return ['ok' => false, 'error' => 'Choose how the payout was sent.'];
        }
        if (trim((string) ($data['reference'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'Enter the transfer reference number.'];
        }
        $p->update(['status' => 'paid', 'failure' => null, 'method' => $data['method'], 'reference' => trim($data['reference']), 'notes' => $data['notes'] ?? null, 'paid_at' => now(), 'paid_by' => $by->id]);
        Notifier::notify($p->supplier->owner_id, 'supplier', 'Payout sent 💸', money($p->net).' was sent to your '.$p->methodLabel().' account. Reference '.$p->reference.' ('.$p->code.').', route('supplier.payouts', [], false));
        Notifier::audit('Paid payout', $p->code.' · '.$p->supplier->name.' · '.money($p->net).' · '.$p->reference, $by);

        return ['ok' => true];
    }

    /** Completed / cancelled requests holding money that has not been released yet. */
    public static function awaitingRelease(?int $supplierId = null): Collection
    {
        return RentalRequest::where(fn ($q) => $q->where('status', 'completed')->orWhere(fn ($c) => $c->where('status', 'cancelled')->where(fn ($f) => $f->whereNull('cancel_free')->orWhere('cancel_free', false))))
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->whereNotIn('id', Payout::select('rental_request_id'))
            ->with(['payments', 'supplier', 'customer'])->orderBy('updated_at')->get()
            ->filter(fn ($r) => self::breakdown($r)['net'] > 0)->values();
    }

    /** Money totals for one supplier, or for every supplier (keyed by supplier id). */
    public static function summary(?int $supplierId = null): array
    {
        $blank = ['escrow' => 0.0, 'escrowCount' => 0, 'awaiting' => 0.0, 'pending' => 0.0, 'pendingCount' => 0, 'paid' => 0.0, 'commission' => 0.0];
        $out = [];
        $add = function (int $sid, string $k, float $v, ?string $count = null) use (&$out, $blank) {
            $out[$sid] ??= $blank;
            $out[$sid][$k] = r2($out[$sid][$k] + $v);
            if ($count) {
                $out[$sid][$count]++;
            }
        };
        RentalRequest::whereIn('status', Finance::ACTIVE)->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->with('payments')->get()
            ->each(function ($r) use ($add) {
                if (($h = self::held($r)) > 0) {
                    $add($r->supplier_id, 'escrow', $h, 'escrowCount');
                }
            });
        self::awaitingRelease($supplierId)->each(fn ($r) => $add($r->supplier_id, 'awaiting', self::held($r)));
        Payout::when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->get()->each(function ($p) use ($add) {
            $add($p->supplier_id, $p->isPaid() ? 'paid' : 'pending', $p->net, $p->isPaid() ? null : 'pendingCount');
            $add($p->supplier_id, 'commission', $p->commission);
        });

        return $supplierId ? ($out[$supplierId] ?? $blank) : $out;
    }
}
