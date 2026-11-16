<?php

namespace App\Domain;

use App\Models\RentalRequest;
use App\Models\Supplier;

/**
 * Payment plans, schedule, balances and statuses (port of reference js/core/finance.js, v4).
 *
 *   Standard     Reservation Fee % + Down Payment % + Final Balance (due on the event date)
 *   Full         100 % in one payment
 *   Installment  Reservation Fee + Down Payment + installments the customer proposes; supplier approves.
 *                Only when the event is at least `minMonths` away; every installment due by the event date.
 *
 * Reservation fee and down payment are always CREDITED: Final Balance = Total − Reservation Fee − Down Payment.
 * Deadlines count from acceptance and never pass the event date. Only VERIFIED payments count.
 * Security deposits and damage compensation are tracked separately from rental charges.
 */
class Finance
{
    public const RENTAL_TYPES = ['reservation', 'down', 'installment', 'balance', 'full'];

    public const TYPE_LABEL = [
        'reservation' => 'Reservation Fee', 'down' => 'Down Payment', 'installment' => 'Installment', 'balance' => 'Final Balance',
        'full' => 'Full Payment', 'deposit' => 'Security Deposit', 'deposit_refund' => 'Deposit Refund', 'refund' => 'Rental Refund',
        'compensation' => 'Damage Compensation',
    ];

    public const METHOD_LABEL = ['cod' => 'Cash on Delivery', 'cash' => 'Cash (Over the Counter)', 'bank' => 'Online Bank Transfer', 'debit' => 'Debit Card', 'online' => 'Online payment (Xendit)'];

    public const PLANS = ['standard', 'full', 'installment'];

    public const PLAN_LABEL = ['standard' => 'Standard Payment Plan', 'full' => 'Full Payment Plan', 'installment' => 'Installment Payment Plan'];

    public const ACTIVE = ['accepted', 'in_progress', 'ready_pickup', 'out_delivery', 'ongoing', 'return_pending', 'inspection'];

    public const DEFAULT_PLANS = [
        'standard' => ['enabled' => true, 'resPct' => 10, 'resDays' => 3, 'downPct' => 30, 'downDays' => 14],
        'full' => ['enabled' => true, 'days' => 7],
        'installment' => ['enabled' => false, 'resPct' => 10, 'resDays' => 3, 'downPct' => 20, 'downDays' => 14, 'minMonths' => 2, 'maxInstallments' => 6],
    ];

    public const STATUS_BADGE = [
        'Pending' => 'b-gold', 'Partially Paid' => 'b-sky', 'Fully Paid' => 'b-green', 'Overdue' => 'b-red',
        'Refunded' => 'b-purple', 'Partially Refunded' => 'b-purple', 'Closed' => 'b-gray',
    ];

    /** Supplier payment-plan settings merged with defaults. */
    public static function planConfig(?Supplier $s): array
    {
        $c = $s?->payment_plans ?? [];
        $out = [];
        foreach (self::PLANS as $k) {
            $out[$k] = array_merge(self::DEFAULT_PLANS[$k], $c[$k] ?? []);
        }

        return $out;
    }

    /** Enabled plan keys. */
    public static function plansOf(?Supplier $s): array
    {
        return array_values(array_filter(self::PLANS, fn ($k) => ! empty(self::planConfig($s)[$k]['enabled'])));
    }

    /** Validate plan settings. Returns an error message or null. */
    public static function validatePlanConfig(array $cfg): ?string
    {
        if (! collect(self::PLANS)->contains(fn ($k) => ! empty($cfg[$k]['enabled']))) {
            return 'Enable at least one payment plan.';
        }
        foreach (['standard', 'installment'] as $k) {
            $c = $cfg[$k] ?? null;
            if (! $c || empty($c['enabled'])) {
                continue;
            }
            $n = self::PLAN_LABEL[$k];
            foreach (['resPct', 'downPct'] as $f) {
                if (! is_numeric($c[$f] ?? null) || $c[$f] < 0 || $c[$f] > 100) {
                    return $n.': percentages must be between 0% and 100%.';
                }
            }
            if ($c['resPct'] + $c['downPct'] > 100) {
                return $n.': the reservation fee and down payment together cannot exceed 100% of the total rental amount.';
            }
            foreach (['resDays', 'downDays'] as $f) {
                if (! self::wholeIn($c[$f] ?? null, 0, 365)) {
                    return $n.': payment deadlines must be whole days (0–365).';
                }
            }
        }
        if (! empty($cfg['installment']['enabled'])) {
            $i = $cfg['installment'];
            if (! self::wholeIn($i['minMonths'] ?? null, 1, 24)) {
                return 'Installment Payment Plan: the minimum advance booking must be 1–24 months.';
            }
            if (! self::wholeIn($i['maxInstallments'] ?? null, 1, 24)) {
                return 'Installment Payment Plan: the maximum number of installments must be 1–24.';
            }
            if ($i['resPct'] + $i['downPct'] >= 100) {
                return 'Installment Payment Plan: leave a balance to divide into installments (reservation fee + down payment must be below 100%).';
            }
        }
        if (! empty($cfg['full']['enabled']) && ! self::wholeIn($cfg['full']['days'] ?? null, 0, 365)) {
            return 'Full Payment Plan: the payment deadline must be whole days (0–365).';
        }

        return null;
    }

    private static function wholeIn(mixed $v, int $min, int $max): bool
    {
        return is_numeric($v) && (float) $v == (int) $v && $v >= $min && $v <= $max;
    }

    /** Plan description shown to customers. */
    public static function planSummary(?Supplier $s, string $plan): string
    {
        $c = self::planConfig($s)[$plan];
        $dd = fn ($n) => (int) $n === 0 ? 'right after acceptance' : 'within '.$n.' day'.($n > 1 ? 's' : '').' of acceptance';
        if ($plan === 'full') {
            return 'Pay 100% of the total in one payment '.$dd($c['days']).'. No separate reservation fee or down payment.';
        }
        if ($plan === 'standard') {
            return 'Reservation Fee '.$c['resPct'].'% ('.$dd($c['resDays']).') + Down Payment '.$c['downPct'].'% ('.$dd($c['downDays']).') + Final Balance '.(100 - $c['resPct'] - $c['downPct']).'% on or before the event date. Fees are credited to your total.';
        }

        return 'Reservation Fee '.$c['resPct'].'% + Down Payment '.$c['downPct'].'%, then the remaining '.(100 - $c['resPct'] - $c['downPct']).'% in installments you propose (up to '.$c['maxInstallments'].'), approved by the supplier and finished by the event date. Requires booking at least '.$c['minMonths'].' month'.($c['minMonths'] > 1 ? 's' : '').' before the event.';
    }

    /** Installment eligibility: the event must be at least `minMonths` months away. */
    public static function installmentEligibility(?Supplier $s, ?string $eventDate): array
    {
        $c = self::planConfig($s)['installment'];
        if (empty($c['enabled'])) {
            return ['ok' => false, 'message' => 'This supplier does not offer the Installment Payment Plan.'];
        }
        $earliest = add_months(today_iso(), (int) $c['minMonths']);
        if (! $eventDate || $eventDate < $earliest) {
            return ['ok' => false, 'minMonths' => (int) $c['minMonths'], 'earliest' => $earliest, 'message' => 'Installments require booking at least '.$c['minMonths'].' month'.($c['minMonths'] > 1 ? 's' : '').' before the event (event on or after '.fmt_date($earliest).').'];
        }

        return ['ok' => true, 'minMonths' => (int) $c['minMonths'], 'earliest' => $earliest];
    }

    /** Evenly divided suggestion: n payments spread between $from and the event date. */
    public static function suggestInstallments(float $balance, int $n, string $from, string $eventDate): array
    {
        $n = max(1, $n);
        $span = max(0, diff_days($from, $eventDate));
        $each = floor($balance / $n * 100) / 100;
        $items = [];
        $acc = 0.0;
        for ($i = 1; $i <= $n; $i++) {
            $amt = $i === $n ? r2($balance - $acc) : $each;
            $acc = r2($acc + $amt);
            $items[] = ['due' => $i === $n ? $eventDate : add_days($from, (int) round($span * $i / $n)), 'amount' => $amt];
        }

        return $items;
    }

    /** Validate a customer-proposed installment schedule. Returns an error or null. */
    public static function validateInstallments(array $items, float $balance, string $eventDate, ?string $earliestDue = null, ?int $maxN = null): ?string
    {
        if (! $items) {
            return 'Add at least one installment.';
        }
        if ($maxN && count($items) > $maxN) {
            return 'This supplier allows at most '.$maxN.' installments.';
        }
        $prev = '';
        foreach (array_values($items) as $i => $it) {
            $n = $i + 1;
            if (! ((float) ($it['amount'] ?? 0) > 0)) {
                return 'Installment '.$n.' needs an amount greater than zero.';
            }
            if (empty($it['due'])) {
                return 'Installment '.$n.' needs a due date.';
            }
            if ($earliestDue && $it['due'] < $earliestDue) {
                return 'Installment '.$n.' cannot be due before '.fmt_date($earliestDue).'.';
            }
            if ($it['due'] > $eventDate) {
                return 'Installment '.$n.' is due after the event date ('.fmt_date($eventDate).'). All installments must be completed on or before the event date.';
            }
            if ($prev && $it['due'] <= $prev) {
                return 'Installment due dates must be in order, one after another.';
            }
            $prev = $it['due'];
        }
        $sum = r2(array_sum(array_map(fn ($x) => (float) $x['amount'], $items)));
        if (abs($sum - $balance) > 0.009) {
            return 'Installments add up to '.money($sum).' but the installment balance is '.money($balance).'. They must match exactly.';
        }

        return null;
    }

    /** Due schedule built from the acceptance date, the event date and the price snapshot. */
    public static function schedule(RentalRequest $req, ?array $p = null): array
    {
        $p ??= $req->pricing;
        $A = $req->accepted_at ? substr((string) $req->accepted_at, 0, 10) : null;
        if (! $p || ! $A) {
            return [];
        }
        $ev = $req->event_date ?: $req->start_date;
        $E = $ev < $A ? $A : $ev;
        $due = function ($days) use ($A, $E) {
            $d = add_days($A, (int) $days);

            return $d > $E ? $E : $d;
        };
        $t = $p['terms'] ?? [];
        $plan = $p['plan'] ?? 'full';
        $items = [];
        if ($plan === 'full') {
            $items[] = ['key' => 'full', 'type' => 'full', 'label' => 'Full Payment', 'due' => $due($t['days'] ?? 0), 'amount' => (float) $p['total']];
        } else {
            if (($p['reservationFee'] ?? 0) > 0) {
                $items[] = ['key' => 'res', 'type' => 'reservation', 'label' => 'Reservation Fee ('.($t['resPct'] ?? 0).'%)', 'due' => $due($t['resDays'] ?? 0), 'amount' => (float) $p['reservationFee']];
            }
            if (($p['downPayment'] ?? 0) > 0) {
                $items[] = ['key' => 'down', 'type' => 'down', 'label' => 'Down Payment ('.($t['downPct'] ?? 0).'%)', 'due' => $due($t['downDays'] ?? 0), 'amount' => (float) $p['downPayment']];
            }
            if ($plan === 'installment') {
                $ip = $req->installment_plan;
                if ($ip && ($ip['status'] ?? '') === 'approved') {
                    $n = count($ip['items']);
                    foreach ($ip['items'] as $i => $it) {
                        $d = $it['due'] > $E ? $E : ($it['due'] < $A ? $A : $it['due']);
                        $items[] = ['key' => 'inst'.($i + 1), 'type' => 'installment', 'label' => 'Installment '.($i + 1).' of '.$n, 'due' => $d, 'amount' => r2($it['amount'])];
                    }
                } elseif (($p['finalBalance'] ?? 0) > 0) {
                    $items[] = ['key' => 'instx', 'type' => 'installment', 'label' => 'Installment balance (schedule awaiting supplier approval)', 'due' => $E, 'amount' => (float) $p['finalBalance']];
                }
            } elseif (($p['finalBalance'] ?? 0) > 0) {
                $items[] = ['key' => 'bal', 'type' => 'balance', 'label' => 'Final Balance', 'due' => $E, 'amount' => (float) $p['finalBalance']];
            }
        }
        $cum = 0.0;
        foreach ($items as &$it) {
            $cum = r2($cum + $it['amount']);
            $it['cumulative'] = $cum;
        }

        return $items;
    }

    public static function summary(RentalRequest $req): array
    {
        $p = $req->pricing ?: Pricing::quoteRequest($req);
        $pays = $req->relationLoaded('payments') ? $req->payments : $req->payments()->get();
        $pays = $pays->sortBy(fn ($x) => (string) $x->submitted_at)->values();
        $ver = $pays->where('status', 'verified');
        $sumT = fn ($list, array $types) => r2($list->whereIn('type', $types)->sum('amount'));

        $paidRental = $sumT($ver, self::RENTAL_TYPES);
        $pendingRental = $sumT($pays->where('status', 'pending'), self::RENTAL_TYPES);
        $refunded = $sumT($ver, ['refund']);
        $depositPaid = $sumT($ver, ['deposit']);
        $depositRefunded = $sumT($ver, ['deposit_refund']);
        $insp = $req->inspection;
        $compensation = $insp && ($insp['confirmed'] ?? true) !== false ? r2($insp['compensation'] ?? 0) : 0.0;
        $compPaidDirect = $sumT($ver, ['compensation']);
        $depositApplied = $insp && ($insp['settlement'] ?? '') === 'deducted' ? min($compensation, $depositPaid) : 0.0;
        $waived = $insp && ($insp['settlement'] ?? '') === 'waived' ? $compensation : 0.0;
        $compensationDue = r2(max(0, $compensation - $compPaidDirect - $depositApplied - $waived));
        $payable = (float) $p['total'];
        $balance = r2(max(0, $payable - $paidRental));

        $grace = (int) Settings::get('overdueGraceDays', 0);
        $active = in_array($req->status, self::ACTIVE, true);
        $sched = array_map(function ($it) use ($paidRental, $active, $grace) {
            $paid = $paidRental >= $it['cumulative'] - 0.001;
            $overdue = ! $paid && $active && today_iso() > add_days($it['due'], $grace);
            $it['state'] = $paid ? 'paid' : ($overdue ? 'overdue' : 'upcoming');

            return $it;
        }, self::schedule($req, $p));
        $next = collect($sched)->first(fn ($s) => $s['state'] !== 'paid');
        $overdueItems = array_values(array_filter($sched, fn ($s) => $s['state'] === 'overdue'));
        $overdueAmount = $overdueItems ? r2(end($overdueItems)['cumulative'] - $paidRental) : 0.0;
        $reservationConfirmed = isset($sched[0]) && $sched[0]['state'] === 'paid';

        if (in_array($req->status, ['declined', 'cancelled'], true) && ! $paidRental) {
            $status = 'Closed';
        } elseif ($refunded > 0) {
            $status = $refunded >= $paidRental ? 'Refunded' : 'Partially Refunded';
        } elseif ($balance <= 0) {
            $status = 'Fully Paid';
        } elseif ($overdueItems) {
            $status = 'Overdue';
        } elseif ($paidRental > 0) {
            $status = 'Partially Paid';
        } else {
            $status = 'Pending';
        }

        $depositRequired = (float) ($p['deposit'] ?? 0);
        $depositStatus = 'Not collected';
        if ($depositPaid > 0) {
            $depositStatus = 'Held';
        }
        if ($depositRefunded > 0 || $depositApplied > 0) {
            $depositStatus = ($depositRefunded + $depositApplied >= $depositPaid - 0.001)
                ? ($depositApplied > 0 ? 'Settled (partly applied)' : 'Refunded')
                : 'Partially refunded';
        }

        return [
            'pricing' => $p, 'payments' => $pays, 'payable' => $payable, 'total' => (float) $p['total'],
            'paidRental' => $paidRental, 'pendingRental' => $pendingRental, 'balance' => $balance, 'refunded' => $refunded,
            'status' => $status, 'schedule' => $sched, 'next' => $next, 'overdueAmount' => $overdueAmount, 'isOverdue' => (bool) $overdueItems,
            'plan' => $p['plan'] ?? 'full', 'reservationFee' => (float) ($p['reservationFee'] ?? 0), 'downPayment' => (float) ($p['downPayment'] ?? 0),
            'finalBalance' => (float) ($p['finalBalance'] ?? 0), 'reservationConfirmed' => $reservationConfirmed,
            'installmentPlan' => $req->installment_plan,
            'depositRequired' => $depositRequired, 'depositPaid' => $depositPaid, 'depositRefunded' => $depositRefunded,
            'depositApplied' => $depositApplied, 'depositHeld' => r2(max(0, $depositPaid - $depositRefunded - $depositApplied)), 'depositStatus' => $depositStatus,
            'compensation' => $compensation, 'compensationDue' => $compensationDue, 'compPaidDirect' => $compPaidDirect,
        ];
    }

    /** Suggested next payment (never more than what is left of that stage). */
    public static function suggestedNext(RentalRequest $req): array
    {
        $s = $req->fin();
        if ($s['next']) {
            return ['type' => $s['next']['type'], 'stage' => $s['next']['key'], 'amount' => r2(max(0, $s['next']['cumulative'] - $s['paidRental'] - $s['pendingRental'])), 'label' => $s['next']['label'], 'due' => $s['next']['due']];
        }

        return ['type' => 'balance', 'amount' => r2(max(0, $s['balance'] - $s['pendingRental'])), 'label' => 'Remaining balance'];
    }

    public static function badge(string $status): string
    {
        return self::STATUS_BADGE[$status] ?? 'b-gray';
    }

    /* ---------- platform money split (app: payouts through Xendit) ---------- */

    /** Share of a request's charges that is SALES income (consumables). */
    public static function salesShare(?array $pricing): float
    {
        $total = (float) ($pricing['total'] ?? 0);

        return $total > 0 ? min(1, max(0, (float) ($pricing['salesSubtotal'] ?? 0) / $total)) : 0.0;
    }

    /** ['rental' => …, 'sales' => …] for an amount paid on a request. */
    public static function split(?array $pricing, float $amount): array
    {
        $sales = r2($amount * self::salesShare($pricing));

        return ['rental' => r2($amount - $sales), 'sales' => $sales];
    }

    /** Verified rental-type payments (minus refunds) split into rental and sales income. */
    public static function incomeSplit(iterable $payments): array
    {
        $out = ['rental' => 0.0, 'sales' => 0.0];
        foreach ($payments as $p) {
            if ($p->status !== 'verified' || ! in_array($p->type, array_merge(self::RENTAL_TYPES, ['refund']), true)) {
                continue;
            }
            $s = self::split($p->rentalRequest?->pricing, (float) $p->amount * ($p->type === 'refund' ? -1 : 1));
            $out['rental'] = r2($out['rental'] + $s['rental']);
            $out['sales'] = r2($out['sales'] + $s['sales']);
        }

        return $out;
    }
}
