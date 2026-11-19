<?php

namespace App\Domain;

use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Escrow and scheduled supplier payouts (marketplace model, like Shopee / Grab).
 *
 * Customers pay PartyRent (online, bank transfer, debit), except cash, which the customer hands to the shop.
 * PartyRent holds its money while the rental runs. When the rental is completed, its EARNINGS are added to
 * the shop's next payout (one Payout row per rental):
 *
 *   income        verified rental/sales payments − rental refunds, split into items + delivery (+ service fee)
 *   commission    items × commissionPct (platform setting, frozen on the earnings); delivery pays no commission
 *   voucher       PartyRent-funded Free Shipping voucher, paid to the supplier
 *   compensation  damage compensation (paid directly or deducted from the deposit), passed through with no fee
 *   earned        items − commission + delivery + voucher + compensation
 *   cashKept      cash the shop collected itself (it already has that money) − cash refunds it paid back
 *   net           earned − cashKept   (negative: the shop kept more cash than it earned and owes the difference)
 *
 * PAYOUTS (PayoutBatch) go out on the shop's schedule: every 15 days (1st–15th, 16th–end of month) or once a month.
 * When a period ends, all its earnings are added up with any balance carried from before. A positive total is
 * sent to the shop's GCash / bank (by Xendit or by hand); zero or negative means nothing is sent and the
 * balance is carried into the next payout. A shop that owes PartyRent for more than OWE_DAYS cannot take new
 * cash payments until it is settled. Security deposits are never supplier income.
 */
class Payouts
{
    public const SCHEDULES = ['half_month' => 'Every 15 days', 'monthly' => 'Once a month'];

    public const SCHEDULE_HINT = [
        'half_month' => 'Twice a month: for the 1st–15th (sent on the 16th) and for the 16th–end of the month (sent on the 1st).',
        'monthly' => 'Once a month: for the whole month, sent on the 1st of the next month.',
    ];

    /** Days a shop may owe PartyRent before cash payments are paused. */
    public const OWE_DAYS = 30;

    public static function pct(): float
    {
        return (float) Settings::get('commissionPct', 5);
    }

    public static function isCash(?string $method): bool
    {
        return in_array($method, ['cash', 'cod'], true);
    }

    /** What a request is worth to its supplier, from verified payments (see the class comment). */
    public static function breakdown(RentalRequest $r, ?float $pct = null): array
    {
        $f = $r->fin();
        $p = $r->pricing ?? [];
        $pct ??= r2(self::pct() + (float) ($p['commissionExtraPct'] ?? 0));
        $income = r2(max(0, $f['paidRental'] - $f['refunded']));
        $delivery = r2(min($income, (float) ($p['deliveryFee'] ?? 0)));
        // Change list: service fees go to the platform and are not part of the supplier's payout share.
        $service = r2(min($income - $delivery, (float) ($p['serviceFee'] ?? 0)));
        $items = r2($income - $delivery - $service);
        $voucher = $r->status === 'completed' ? r2((float) ($p['voucher'] ?? 0)) : 0.0;
        $comp = r2($f['compPaidDirect'] + $f['depositApplied']);
        $commission = r2($items * $pct / 100);
        $earned = r2($items - $commission + $delivery + $voucher + $comp);
        $cash = self::cashKept($r, $f);

        return [
            'income' => $income, 'items' => $items, 'delivery' => $delivery, 'service' => $service, 'voucher' => $voucher,
            'pct' => $pct, 'commission' => $commission, 'compensation' => $comp,
            'earned' => $earned, 'cashKept' => $cash,
            // what PartyRent sends for this rental = earned − cash the shop already has
            'net' => r2($earned - $cash),
        ];
    }

    /** Cash the shop collected for this rental and kept (rent, damages, deposit used for damages) minus cash it refunded. */
    public static function cashKept(RentalRequest $r, ?array $f = null): float
    {
        $f ??= $r->fin();
        $ver = ($r->relationLoaded('payments') ? $r->payments : $r->payments()->get())
            ->where('status', 'verified')->filter(fn ($p) => self::isCash($p->method));
        $in = $ver->whereIn('type', array_merge(Finance::RENTAL_TYPES, ['compensation']))->sum('amount');
        $out = $ver->where('type', 'refund')->sum('amount');
        $depositUsed = min((float) $f['depositApplied'], (float) $ver->where('type', 'deposit')->sum('amount'));

        return r2($in - $out + $depositUsed);
    }

    /** Money PartyRent is holding for this request (shown to the supplier as "held by PartyRent"). */
    public static function held(RentalRequest $r): float
    {
        $b = self::breakdown($r);

        return r2(max(0, $b['income'] + $b['voucher'] + $b['compensation'] - $b['cashKept']));
    }

    /* ---------------- schedule ---------------- */

    public static function scheduleOf(Supplier $s): string
    {
        return isset(self::SCHEDULES[$s->payout_schedule]) ? $s->payout_schedule : 'half_month';
    }

    /** [start, end] of the payout period containing $date. */
    public static function period(string $date, string $schedule): array
    {
        $d = Carbon::parse($date);
        if ($schedule === 'monthly' || $d->day > 15) {
            return [$schedule === 'monthly' ? $d->copy()->startOfMonth()->toDateString() : $d->copy()->day(16)->toDateString(), $d->copy()->endOfMonth()->toDateString()];
        }

        return [$d->copy()->startOfMonth()->toDateString(), $d->copy()->day(15)->toDateString()];
    }

    /** Date of the shop's next payout (the day after the current period ends). */
    public static function nextPayoutDate(Supplier $s, ?string $today = null): string
    {
        return add_days(self::period($today ?? today_iso(), self::scheduleOf($s))[1], 1);
    }

    /** End of the latest period that is fully over (its earnings can be paid out). */
    public static function lastClosedEnd(string $schedule, string $today): string
    {
        $y = add_days($today, -1);
        [$start, $end] = self::period($y, $schedule);

        return $end === $y ? $y : add_days($start, -1);
    }

    public static function nextCode(): string
    {
        return 'PO-'.(10000 + Settings::nextSeq('payout'));
    }

    /* ---------------- earnings ---------------- */

    /** Add a completed (or cancelled-with-money-kept) request's earnings to the shop's next payout. */
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
        if (abs($b['earned']) < 0.005 && abs($b['cashKept']) < 0.005) {
            return ['ok' => false, 'error' => 'There is nothing to pay out for '.$r->code.'.'];
        }
        $p = DB::transaction(fn () => Payout::create([
            'code' => 'EA-'.(10000 + Settings::nextSeq('earning')), 'supplier_id' => $r->supplier_id, 'rental_request_id' => $r->id,
            'income' => $b['income'], 'commission_pct' => $b['pct'], 'commission' => $b['commission'], 'delivery' => $b['delivery'], 'voucher' => $b['voucher'], 'compensation' => $b['compensation'],
            'earned' => $b['earned'], 'cash_kept' => $b['cashKept'], 'net' => $b['net'], 'status' => 'pending',
        ]));
        $s = $r->supplier;
        $when = fmt_date(self::nextPayoutDate($s));
        $msg = $b['net'] >= 0
            ? money($b['net']).' from '.$r->code.' was added to your next payout ('.$when.').'.($b['cashKept'] > 0 ? ' The '.money($b['cashKept']).' you collected in cash is already yours and was taken off.' : '')
            : 'You collected '.money($b['cashKept']).' in cash for '.$r->code.' and keep it. PartyRent\'s share of '.money(-$b['net']).' will be taken from your next payout ('.$when.').';
        self::tellShop($s, 'Earnings added', $msg);
        Notifier::audit('Earnings added', $r->code.' · '.money($b['earned']).' earned · '.money($b['cashKept']).' cash kept · '.money($b['net']).' to pay');

        return ['ok' => true, 'payout' => $p];
    }

    /** Completed / cancelled requests holding money whose earnings were not added yet. */
    public static function awaitingRelease(?int $supplierId = null): Collection
    {
        return RentalRequest::where(fn ($q) => $q->where('status', 'completed')->orWhere(fn ($c) => $c->where('status', 'cancelled')->where(fn ($f) => $f->whereNull('cancel_free')->orWhere('cancel_free', false))))
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->whereNotIn('id', Payout::select('rental_request_id'))
            ->with(['payments', 'supplier', 'customer'])->orderBy('updated_at')->get()
            ->filter(function ($r) {
                $b = self::breakdown($r);

                return abs($b['earned']) >= 0.005 || abs($b['cashKept']) >= 0.005;
            })->values();
    }

    /* ---------------- payouts ---------------- */

    /**
     * Create the payouts whose period is over (runs daily, and when a payouts page opens). One payout per shop per
     * period, with every earning added up to the period end. Returns how many payouts were created.
     */
    public static function closeDue(?string $today = null, ?int $supplierId = null): int
    {
        $today ??= today_iso();
        $ids = Payout::whereNull('payout_batch_id')->where('status', 'pending')->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->distinct()->pluck('supplier_id');
        $made = 0;
        foreach (Supplier::whereIn('id', $ids)->get() as $s) {
            $batch = DB::transaction(function () use ($s, $today) {
                Supplier::whereKey($s->id)->lockForUpdate()->first();
                $schedule = self::scheduleOf($s);
                $end = self::lastClosedEnd($schedule, $today);
                $lines = Payout::where('supplier_id', $s->id)->whereNull('payout_batch_id')->where('status', 'pending')
                    ->where('created_at', '<', Carbon::parse(add_days($end, 1)))->get();
                if ($lines->isEmpty()) {
                    return null;
                }
                $carried = PayoutBatch::where('supplier_id', $s->id)->where('status', 'carried')->whereNull('carried_to_id')->get();
                $earned = r2($lines->sum('earned'));
                $cash = r2($lines->sum('cash_kept'));
                $carriedIn = r2($carried->sum('net'));
                $net = r2($earned - $cash + $carriedIn);
                $first = $lines->min(fn ($l) => $l->created_at->toDateString());
                $b = PayoutBatch::create([
                    'code' => self::nextCode(), 'supplier_id' => $s->id, 'schedule' => $schedule,
                    'period_start' => min(self::period($end, $schedule)[0], $first), 'period_end' => $end,
                    'earned' => $earned, 'commission' => r2($lines->sum('commission')), 'cash_kept' => $cash, 'carried_in' => $carriedIn,
                    'net' => $net, 'status' => $net > 0.004 ? 'pending' : 'carried',
                ]);
                Payout::whereIn('id', $lines->pluck('id'))->update(['payout_batch_id' => $b->id, 'status' => 'scheduled']);
                PayoutBatch::whereIn('id', $carried->pluck('id'))->update(['carried_to_id' => $b->id]);

                return $b;
            });
            if (! $batch) {
                continue;
            }
            $made++;
            self::announce($batch);
            if ($batch->status === 'pending') {
                XenditPayouts::autoSend($batch);
            }
        }

        return $made;
    }

    private static function announce(PayoutBatch $b): void
    {
        $s = $b->supplier;
        $period = $b->periodLabel();
        if ($b->status === 'pending') {
            self::tellShop($s, 'Payout '.$b->code.' is ready 💸', money($b->net).' for '.$period.'. '.($s->hasPayoutAccount()
                ? 'PartyRent sends it to your '.$s->payoutAccountLabel().' within 2 business days.'
                : 'Add your GCash or bank account on the Payouts page so PartyRent can send it.'));
            Notifier::notify('admin', 'admin', 'Payout to send', $s->name.' · '.money($b->net).' for '.$period.' ('.$b->code.').', route('admin.payouts', [], false));
            Notifier::audit('Payout prepared', $b->code.' · '.$s->name.' · '.money($b->net).' · '.$period);
        } elseif ($b->isOwed()) {
            self::tellShop($s, 'No payout this time', 'For '.$period.' you collected '.money($b->cash_kept).' in cash, more than PartyRent owed you. The difference, '.money(-$b->net).', will be taken from your next payout.');
            Notifier::audit('Payout carried (shop owes)', $b->code.' · '.$s->name.' · '.money($b->net));
        } else {
            self::tellShop($s, 'No payout this time', 'For '.$period.' the cash you collected covered your earnings exactly, so there is nothing to send.');
        }
    }

    /** Payout news for the shop: the owner, and the shop's cashiers. */
    private static function tellShop(Supplier $s, string $title, string $body): void
    {
        Notifier::notify($s->owner_id, 'supplier', $title, $body, route('supplier.payouts', [], false));
        foreach (\App\Models\ShopStaff::where('supplier_id', $s->id)->withRole('cashier')->where('status', 'active')->pluck('user_id') as $uid) {
            Notifier::notify($uid, 'cashier', $title, $body, route('cashier.payouts', [], false));
        }
    }

    /** The money arrived: the payout, its earnings and any balance carried into it are paid. */
    public static function markLinesPaid(PayoutBatch $b): void
    {
        $ids = PayoutBatch::where('carried_to_id', $b->id)->pluck('id')->push($b->id);
        Payout::whereIn('payout_batch_id', $ids)->update(['status' => 'paid']);
    }

    /** Admin sent the money by hand: record the transfer reference. */
    public static function markPaid(PayoutBatch $p, array $data, User $by): array
    {
        if ($p->status !== 'pending') {
            return ['ok' => false, 'error' => match ($p->status) {
                'paid' => $p->code.' is already paid.',
                'sending' => $p->code.' is being sent through Xendit. Use "Check status" instead.',
                default => $p->code.' has nothing to send.',
            }];
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
        self::markLinesPaid($p);
        self::tellShop($p->supplier, 'Payout sent 💸', money($p->net).' for '.$p->periodLabel().' was sent to your '.$p->methodLabel().' account. Reference '.$p->reference.' ('.$p->code.').');
        Notifier::audit('Paid payout', $p->code.' · '.$p->supplier->name.' · '.money($p->net).' · '.$p->reference, $by);

        return ['ok' => true];
    }

    /** The shop paid what it owed directly (e.g. no more payouts coming): close the balance. */
    public static function settle(PayoutBatch $p, array $data, User $by): array
    {
        if (! $p->isOwed() || $p->carried_to_id) {
            return ['ok' => false, 'error' => $p->code.' has no open balance to settle.'];
        }
        if (trim((string) ($data['reference'] ?? '')) === '') {
            return ['ok' => false, 'error' => 'Enter the reference of the shop\'s payment.'];
        }
        $p->update(['status' => 'settled', 'reference' => trim($data['reference']), 'notes' => $data['notes'] ?? null, 'paid_at' => now(), 'paid_by' => $by->id]);
        self::markLinesPaid($p);
        self::tellShop($p->supplier, 'Balance settled', 'PartyRent received your '.money(-$p->net).' ('.$p->code.'). Thank you! Cash payments are open for your shop.');
        Notifier::audit('Settled shop balance', $p->code.' · '.$p->supplier->name.' · '.money(-$p->net).' · '.$p->reference, $by);

        return ['ok' => true];
    }

    /** What the shop owes PartyRent right now (positive number), and since when. */
    public static function owed(Supplier $s): array
    {
        $open = PayoutBatch::where('supplier_id', $s->id)->where('status', 'carried')->whereNull('carried_to_id')->where('net', '<', 0)->orderBy('period_end')->get();

        return ['amount' => r2(-$open->sum('net')), 'since' => $open->first()?->period_end?->toDateString()];
    }

    /** Cash is paused for a shop that has owed PartyRent for more than OWE_DAYS. */
    public static function cashPaused(Supplier $s): bool
    {
        $o = self::owed($s);

        return $o['amount'] > 0 && $o['since'] < add_days(today_iso(), -self::OWE_DAYS);
    }

    /** Money totals for one supplier, or for every supplier (keyed by supplier id). */
    public static function summary(?int $supplierId = null): array
    {
        $blank = ['escrow' => 0.0, 'escrowCount' => 0, 'awaiting' => 0.0, 'next' => 0.0, 'nextCount' => 0, 'pending' => 0.0, 'pendingCount' => 0, 'paid' => 0.0, 'commission' => 0.0, 'owed' => 0.0];
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
            if ($p->payout_batch_id === null && $p->status === 'pending') {
                $add($p->supplier_id, 'next', $p->net, 'nextCount');
            }
            $add($p->supplier_id, 'commission', $p->commission);
        });
        PayoutBatch::when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))->get()->each(function ($b) use ($add) {
            if (in_array($b->status, ['pending', 'sending'], true)) {
                $add($b->supplier_id, 'pending', $b->net, 'pendingCount');
            } elseif ($b->status === 'paid') {
                $add($b->supplier_id, 'paid', $b->net);
            } elseif ($b->isOwed() && ! $b->carried_to_id) {
                $add($b->supplier_id, 'owed', -$b->net);
                $add($b->supplier_id, 'next', $b->net); // taken from the next payout
            }
        });

        return $supplierId ? ($out[$supplierId] ?? $blank) : $out;
    }
}
