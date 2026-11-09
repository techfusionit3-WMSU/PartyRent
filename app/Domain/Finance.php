<?php

namespace App\Domain;

use App\Models\RentalRequest;

/**
 * Payment schedule, balances and statuses (port of js/core/finance.js).
 * Only VERIFIED payments count. Security deposits and compensation are tracked
 * separately from rental charges.
 */
class Finance
{
    public const RENTAL_TYPES = ['reservation', 'down', 'installment', 'balance', 'full'];

    public const ACTIVE = ['accepted', 'in_progress', 'ready_pickup', 'out_delivery', 'ongoing', 'return_pending', 'inspection'];

    public const STATUS_BADGE = [
        'Unpaid' => 'b-gray', 'Pending Verification' => 'b-gold', 'Partially Paid' => 'b-sky', 'Fully Paid' => 'b-green',
        'Overdue' => 'b-red', 'Refunded' => 'b-purple', 'Partially Refunded' => 'b-purple',
    ];

    /**
     * Build the due schedule from the acceptance date and the price snapshot.
     * $pricing / $acceptedAt / $start / $method can be passed to preview a schedule before acceptance.
     */
    public static function schedule(array $p, ?string $acceptedAt, string $start, string $method): array
    {
        if (! $p || ! $acceptedAt) {
            return [];
        }
        $A = $acceptedAt;
        $S = $start;
        $lastBefore = add_days($S, -1) < $A ? $A : add_days($S, -1);
        $soon = fn (int $d) => add_days($A, $d) < $lastBefore ? add_days($A, $d) : $lastBefore;
        $items = [];
        $res = (float) ($p['reservationFee'] ?? 0);
        $arr = $p['arrangement'];

        if ($arr === 'full') {
            $items[] = ['key' => 'full', 'label' => 'Full payment', 'due' => $method === 'cod' ? $S : $soon(3), 'amount' => (float) $p['payable']];
        } elseif ($arr === 'reservation') {
            $items[] = ['key' => 'res', 'label' => 'Reservation fee', 'due' => $soon(2), 'amount' => $res];
            $items[] = ['key' => 'bal', 'label' => 'Remaining balance', 'due' => $S, 'amount' => r2($p['payable'] - $res)];
        } elseif ($arr === 'down') {
            $downDue = r2($p['downRequired'] + ($p['reservationCredited'] ? 0 : $res));
            $label = $res ? ($p['reservationCredited'] ? 'Down payment (reservation fee credited)' : 'Down payment + reservation fee') : 'Down payment';
            $items[] = ['key' => 'down', 'label' => $label, 'due' => $soon(3), 'amount' => $downDue];
            $items[] = ['key' => 'bal', 'label' => 'Remaining balance', 'due' => $S, 'amount' => r2($p['payable'] - $downDue)];
        } elseif ($arr === 'installment') {
            if ($res) {
                $items[] = ['key' => 'res', 'label' => 'Reservation fee', 'due' => $soon(2), 'amount' => $res];
            }
            $n = (int) ($p['installments'] ?: 2);
            $rest = r2($p['payable'] - $res);
            $span = max(0, diff_days($A, $S));
            $acc = 0.0;
            for ($i = 1; $i <= $n; $i++) {
                $amt = $i === $n ? r2($rest - $acc) : r2(floor($rest / $n));
                $acc = r2($acc + $amt);
                $off = min($span, max(1, (int) round($span * ($i - 1 + 0.5) / $n)));
                $due = $i === $n ? $S : add_days($A, $off);
                $items[] = ['key' => 'inst'.$i, 'label' => "Installment $i of $n", 'due' => $due, 'amount' => $amt];
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
        $ver = $pays->where('status', 'verified');
        $sumT = fn ($list, array $types) => r2($list->whereIn('type', $types)->sum('amount'));

        $paidRental = $sumT($ver, self::RENTAL_TYPES);
        $pendingRental = $sumT($pays->where('status', 'pending'), self::RENTAL_TYPES);
        $refunded = $sumT($ver, ['refund']);
        $depositPaid = $sumT($ver, ['deposit']);
        $depositRefunded = $sumT($ver, ['deposit_refund']);
        $insp = $req->inspection;
        $compensation = $insp ? r2($insp['compensation'] ?? 0) : 0.0;
        $compPaidDirect = $sumT($ver, ['compensation']);
        $depositApplied = $insp && ($insp['settlement'] ?? '') === 'deducted' ? min($compensation, $depositPaid) : 0.0;
        $waived = $insp && ($insp['settlement'] ?? '') === 'waived' ? $compensation : 0.0;
        $compensationDue = r2(max(0, $compensation - $compPaidDirect - $depositApplied - $waived));
        $payable = (float) $p['payable'];
        $balance = r2(max(0, $payable - $paidRental));

        $grace = (int) Settings::get('overdueGraceDays', 0);
        $active = in_array($req->status, self::ACTIVE, true);
        $sched = array_map(function ($it) use ($paidRental, $active, $grace) {
            $paid = $paidRental >= $it['cumulative'] - 0.001;
            $overdue = ! $paid && $active && today_iso() > add_days($it['due'], $grace);
            $it['state'] = $paid ? 'paid' : ($overdue ? 'overdue' : 'upcoming');

            return $it;
        }, self::schedule($p, $req->accepted_at, $req->start_date, $req->method));
        $next = collect($sched)->first(fn ($s) => $s['state'] !== 'paid');
        $overdueItems = array_values(array_filter($sched, fn ($s) => $s['state'] === 'overdue'));
        $overdueAmount = $overdueItems ? r2(end($overdueItems)['cumulative'] - $paidRental) : 0.0;

        if ($req->status === 'declined' || ($req->status === 'pending' && ! $paidRental && ! $pendingRental)) {
            $status = 'Unpaid';
        } elseif ($refunded > 0) {
            $status = $refunded >= $paidRental ? 'Refunded' : 'Partially Refunded';
        } elseif ($balance <= 0) {
            $status = 'Fully Paid';
        } elseif ($overdueItems) {
            $status = 'Overdue';
        } elseif ($paidRental > 0) {
            $status = 'Partially Paid';
        } elseif ($pendingRental > 0) {
            $status = 'Pending Verification';
        } else {
            $status = 'Unpaid';
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
        $depositHeld = r2(max(0, $depositPaid - $depositRefunded - $depositApplied));

        return [
            'pricing' => $p, 'payments' => $pays, 'payable' => $payable, 'total' => (float) $p['total'],
            'paidRental' => $paidRental, 'pendingRental' => $pendingRental, 'balance' => $balance, 'refunded' => $refunded,
            'status' => $status, 'schedule' => $sched, 'next' => $next, 'overdueAmount' => $overdueAmount, 'isOverdue' => (bool) $overdueItems,
            'reservationFee' => (float) ($p['reservationFee'] ?? 0), 'reservationCredited' => (bool) ($p['reservationCredited'] ?? true),
            'downRequired' => (float) ($p['downRequired'] ?? 0),
            'depositRequired' => $depositRequired, 'depositPaid' => $depositPaid, 'depositRefunded' => $depositRefunded,
            'depositApplied' => $depositApplied, 'depositHeld' => $depositHeld, 'depositStatus' => $depositStatus,
            'compensation' => $compensation, 'compensationDue' => $compensationDue, 'compPaidDirect' => $compPaidDirect,
        ];
    }

    /**
     * Share of a request's charges that is SALES income (consumables). Payments are not itemised, so
     * each rental-type payment is split in the same proportion as the quote: sales subtotal / total.
     * Rentals, services and delivery count as rental income.
     */
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

    /** Suggested amount for the customer's next payment. */
    public static function suggestedNext(RentalRequest $req): array
    {
        $s = $req->fin();
        if ($s['next']) {
            $k = $s['next']['key'];
            $type = $k === 'res' ? 'reservation' : ($k === 'down' ? 'down' : (str_starts_with($k, 'inst') ? 'installment' : ($k === 'full' ? 'full' : 'balance')));

            return ['type' => $type, 'amount' => r2(max(0, $s['next']['cumulative'] - $s['paidRental'] - $s['pendingRental'])), 'label' => $s['next']['label']];
        }

        return ['type' => 'balance', 'amount' => $s['balance'], 'label' => 'Remaining balance'];
    }

    public static function badge(string $status): string
    {
        return self::STATUS_BADGE[$status] ?? 'b-gray';
    }
}
