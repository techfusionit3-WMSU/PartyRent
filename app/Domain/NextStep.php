<?php

namespace App\Domain;

use App\Models\EventBooking;
use App\Models\RentalRequest;
use App\Models\Review;

/**
 * "What happens next" on a rental or an event booking: one sentence saying who acts now and what they do.
 * $viewer is 'customer' or 'shop'. Returns ['turn' => 'you'|'them'|'done', 'text' => string].
 *   you  = the person looking at the page has to do something
 *   them = waiting for the other side
 *   done = nothing left to do (closed or completed)
 * Every sentence goes through __() so it follows the English / Filipino switch.
 */
class NextStep
{
    private static function you(string $t, array $p = []): array { return ['turn' => 'you', 'text' => __($t, $p)]; }
    private static function them(string $t, array $p = []): array { return ['turn' => 'them', 'text' => __($t, $p)]; }
    private static function done(string $t, array $p = []): array { return ['turn' => 'done', 'text' => __($t, $p)]; }

    public static function rental(RentalRequest $r, string $viewer): array
    {
        $c = $viewer === 'customer';
        $f = $r->fin();
        $next = $f['next'];
        $p = [
            'shop' => $r->supplier->name, 'name' => $r->customer->firstName(), 'start' => fmt_date($r->start_date),
            'end' => fmt_date($r->end_date), 'back' => fmt_date(add_days($r->end_date, 1)), 'from' => fmt_date(add_days($r->start_date, -1)),
            'pay' => $next ? __(':label of :amount by :date', ['label' => mb_strtolower(__($next['label'])), 'amount' => money(max(0, r2($next['cumulative'] - $f['paidRental']))), 'date' => fmt_date($next['due'])]) : '',
            'pending' => money($f['pendingRental']), 'overdue' => money($f['overdueAmount']), 'balance' => money($f['balance']),
        ];
        $then = $next ? ' '.__('Next payment: :pay.', $p) : '';

        switch ($r->status) {
            case 'declined':
                return $c ? self::done(':shop declined this request. Your rental list still has the items if you want to try another date or shop.', $p) : self::done('You declined this request.');
            case 'cancelled':
                return self::done('This rental was cancelled.');
            case 'pending':
                return $c ? self::them('Waiting for :shop to accept your request (usually within a day). Nothing is reserved or charged yet.', $p)
                    : self::you('Your turn: accept or decline :name\'s request. Check the items and dates first.', $p);
            case 'completed':
                if ($c && ! Review::where('rental_request_id', $r->id)->exists()) {
                    return self::you('All done. Your turn: rate your rental to help other customers.');
                }

                return self::done('All done. This rental is closed.');
        }

        // money first: a submitted payment to confirm, an installment plan to approve, or an overdue payment
        if ($f['pendingRental'] > 0) {
            return $c ? self::them('Waiting for :shop to confirm your payment of :pending.', $p) : self::you('Your turn: confirm :name\'s payment of :pending.', $p);
        }
        if (($r->installment_plan['status'] ?? null) === 'proposed') {
            return $c ? self::them('Waiting for :shop to approve your installment schedule.', $p) : self::you('Your turn: approve or reject :name\'s installment schedule.', $p);
        }
        if ($f['isOverdue']) {
            return $c ? self::you('Your turn: pay :overdue now. This payment is overdue.', $p) : self::them('Waiting for :name to pay :overdue (overdue). You can send a reminder.', $p);
        }

        $delivery = $r->fulfillment === 'delivery';
        switch ($r->status) {
            case 'accepted':
                if ($r->awaitingDownPayment()) {
                    return $c ? self::you('Your turn: pay the :pay. Your items are reserved once it is confirmed.', $p) : self::them('Waiting for :name to pay the :pay. Items are reserved once you confirm it.', $p);
                }
                if ($c) {
                    return ['turn' => 'them', 'text' => __('Reserved. :shop prepares your items for :start.', $p).$then];
                }

                return self::you('Your turn: start preparing the items for :start.', $p);
            case 'in_progress':
                if ($c) {
                    return ['turn' => 'them', 'text' => __(':shop is preparing your items for :start.', $p).$then];
                }

                return $delivery ? self::you('Your turn: when the items are ready, mark them out for delivery.') : self::you('Your turn: when the items are ready, mark them ready for pickup.');
            case 'ready_pickup':
                if ($c) {
                    return ['turn' => 'you', 'text' => __('Your turn: pick up your items at :shop from :from.', $p).($f['balance'] > 0 ? ' '.__('Pay the remaining :balance first.', $p) : '')];
                }

                return self::them('Waiting for :name to pick up. Record the hand-over (name + photo) when they do.', $p);
            case 'out_delivery':
                return $c ? self::them('Your items are on the way. Please have someone receive them.') : self::you('Your turn: record the hand-over (who received it + a photo) once delivered.');
            case 'ongoing':
                if ($c) {
                    return $delivery ? self::them('Enjoy your event! :shop collects the items on :back.', $p) : self::them('Enjoy your event! Return the items to :shop by :back.', $p);
                }

                return self::them('The items are with :name until :end. Mark the return on :back.', $p);
            case 'return_pending':
                return $c ? self::them(':shop is checking the returned items.', $p) : self::you('Your turn: check the returned items and record the inspection.');
            case 'inspection':
                if (Damage::reviewStatus($r) === 'pending') {
                    return $c ? self::you('Your turn: review the damage charge and accept or dispute it.') : self::them('Waiting for :name to answer the damage charge.', $p);
                }

                return $c ? self::them(':shop is settling your deposit and closing the rental.', $p) : self::you('Your turn: settle the deposit and complete the rental.');
        }

        return self::done(RentalRequest::statusLabel($r->status));
    }

    public static function event(EventBooking $b, string $viewer): array
    {
        $c = $viewer === 'customer';
        $due = $b->payments->firstWhere('status', 'due');
        $pending = $b->payments->firstWhere('status', 'pending');
        $p = [
            'biz' => $b->supplier->name, 'name' => $b->customer->firstName(), 'day' => $b->event_date->format('M j'),
            'until' => ! empty($b->quote['validUntil']) ? fmt_date($b->quote['validUntil']) : '',
            'pending' => $pending ? money((float) $pending->amount) : '',
            'pay' => $due ? __(':label of :amount by :date', ['label' => mb_strtolower($due->label), 'amount' => money((float) $due->amount), 'date' => $due->due_date->format('M j')]) : '',
        ];

        switch ($b->status) {
            case 'inquiry':
                return $c ? self::them('Waiting for :biz to send you a quotation.', $p) : self::you('Your turn: send :name a quotation, or decline.', $p);
            case 'quoted':
                if ($p['until']) {
                    return $c ? self::you('Your turn: accept or decline the quotation by :until. The date is not held yet.', $p) : self::them('Waiting for :name to accept the quotation by :until.', $p);
                }

                return $c ? self::you('Your turn: accept or decline the quotation. The date is not held yet.') : self::them('Waiting for :name to accept the quotation.', $p);
            case 'booked':
            case 'confirmed':
                if ($pending) {
                    return $c ? self::them('Waiting for :biz to confirm your payment of :pending.', $p) : self::you('Your turn: confirm :name\'s payment of :pending.', $p);
                }
                if ($due) {
                    if ($c) {
                        return ['turn' => 'you', 'text' => __('Your turn: pay the :pay.', $p).($b->status === 'booked' ? ' '.__('This holds your date.') : '')];
                    }

                    return self::them('Waiting for :name to pay the :pay.', $p);
                }

                return $c ? self::them('Fully paid. See you on :day!', $p) : self::you('Fully paid. Run the event on :day, then mark it done.', $p);
            case 'completed':
                return self::done('Event done. Thank you!');
        }

        return self::done($b->statusLabel());
    }
}
