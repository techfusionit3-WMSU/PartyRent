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
 */
class NextStep
{
    private static function you(string $t): array { return ['turn' => 'you', 'text' => $t]; }
    private static function them(string $t): array { return ['turn' => 'them', 'text' => $t]; }
    private static function done(string $t): array { return ['turn' => 'done', 'text' => $t]; }

    public static function rental(RentalRequest $r, string $viewer): array
    {
        $c = $viewer === 'customer';
        $shop = $r->supplier->name;
        $cust = $r->customer->firstName();
        $f = $r->fin();
        $next = $f['next'];
        $nextAmt = $next ? max(0, r2($next['cumulative'] - $f['paidRental'])) : 0;
        $nextTxt = $next ? mb_strtolower($next['label']).' of '.money($nextAmt).' by '.fmt_date($next['due']) : '';
        $start = fmt_date($r->start_date);
        $back = fmt_date(add_days($r->end_date, 1));

        switch ($r->status) {
            case 'declined':
                return self::done($c ? $shop.' declined this request. Your rental list still has the items if you want to try another date or shop.' : 'You declined this request.');
            case 'cancelled':
                return self::done('This rental was cancelled.');
            case 'pending':
                return $c ? self::them('Waiting for '.$shop.' to accept your request (usually within a day). Nothing is reserved or charged yet.')
                    : self::you('Your turn: accept or decline '.$cust.'\'s request. Check the items and dates first.');
            case 'completed':
                if ($c && ! Review::where('rental_request_id', $r->id)->exists()) {
                    return self::you('All done. Your turn: rate your rental to help other customers.');
                }

                return self::done('All done. This rental is closed.');
        }

        // money first: a submitted payment to confirm, or an overdue one
        if ($f['pendingRental'] > 0) {
            return $c ? self::them('Waiting for '.$shop.' to confirm your payment of '.money($f['pendingRental']).'.')
                : self::you('Your turn: confirm '.$cust.'\'s payment of '.money($f['pendingRental']).'.');
        }
        if (($r->installment_plan['status'] ?? null) === 'proposed') {
            return $c ? self::them('Waiting for '.$shop.' to approve your installment schedule.') : self::you('Your turn: approve or reject '.$cust.'\'s installment schedule.');
        }
        if ($f['isOverdue']) {
            return $c ? self::you('Your turn: pay '.money($f['overdueAmount']).' now. This payment is overdue.')
                : self::them('Waiting for '.$cust.' to pay '.money($f['overdueAmount']).' (overdue). You can send a reminder.');
        }

        switch ($r->status) {
            case 'accepted':
                if ($r->awaitingDownPayment()) {
                    return $c ? self::you('Your turn: pay the '.$nextTxt.'. Your items are reserved once it is confirmed.')
                        : self::them('Waiting for '.$cust.' to pay the '.$nextTxt.'. Items are reserved once you confirm it.');
                }

                return $c ? self::them('Reserved. '.$shop.' prepares your items for '.$start.'.'.($next ? ' Next payment: '.$nextTxt.'.' : ''))
                    : self::you('Your turn: start preparing the items for '.$start.'.');
            case 'in_progress':
                return $c ? self::them($shop.' is preparing your items for '.$start.'.'.($next ? ' Next payment: '.$nextTxt.'.' : ''))
                    : self::you('Your turn: when the items are ready, mark them '.($r->fulfillment === 'delivery' ? 'out for delivery' : 'ready for pickup').'.');
            case 'ready_pickup':
                return $c ? self::you('Your turn: pick up your items at '.$shop.' from '.fmt_date(add_days($r->start_date, -1)).'.'.($f['balance'] > 0 ? ' Pay the remaining '.money($f['balance']).' first.' : ''))
                    : self::them('Waiting for '.$cust.' to pick up. Record the hand-over (name + photo) when they do.');
            case 'out_delivery':
                return $c ? self::them('Your items are on the way. Please have someone receive them.')
                    : self::you('Your turn: record the hand-over (who received it + a photo) once delivered.');
            case 'ongoing':
                return $c ? self::them('Enjoy your event! '.($r->fulfillment === 'delivery' ? $shop.' collects the items on '.$back.'.' : 'Return the items to '.$shop.' by '.$back.'.'))
                    : self::them('The items are with '.$cust.' until '.fmt_date($r->end_date).'. Mark the return on '.$back.'.');
            case 'return_pending':
                return $c ? self::them($shop.' is checking the returned items.') : self::you('Your turn: check the returned items and record the inspection.');
            case 'inspection':
                if (Damage::reviewStatus($r) === 'pending') {
                    return $c ? self::you('Your turn: review the damage charge and accept or dispute it.') : self::them('Waiting for '.$cust.' to answer the damage charge.');
                }

                return $c ? self::them($shop.' is settling your deposit and closing the rental.') : self::you('Your turn: settle the deposit and complete the rental.');
        }

        return self::done(RentalRequest::statusLabel($r->status));
    }

    public static function event(EventBooking $b, string $viewer): array
    {
        $c = $viewer === 'customer';
        $biz = $b->supplier->name;
        $cust = $b->customer->firstName();
        $due = $b->payments->firstWhere('status', 'due');
        $pending = $b->payments->firstWhere('status', 'pending');
        $pay = fn ($p) => mb_strtolower($p->label).' of '.money((float) $p->amount).' by '.$p->due_date->format('M j');

        switch ($b->status) {
            case 'inquiry':
                return $c ? self::them('Waiting for '.$biz.' to send you a quotation.') : self::you('Your turn: send '.$cust.' a quotation, or decline.');
            case 'quoted':
                $until = ! empty($b->quote['validUntil']) ? ' by '.fmt_date($b->quote['validUntil']) : '';

                return $c ? self::you('Your turn: accept or decline the quotation'.$until.'. The date is not held yet.') : self::them('Waiting for '.$cust.' to accept the quotation'.$until.'.');
            case 'booked':
            case 'confirmed':
                if ($pending) {
                    return $c ? self::them('Waiting for '.$biz.' to confirm your payment of '.money((float) $pending->amount).'.') : self::you('Your turn: confirm '.$cust.'\'s payment of '.money((float) $pending->amount).'.');
                }
                if ($due) {
                    return $c ? self::you('Your turn: pay the '.$pay($due).'.'.($b->status === 'booked' ? ' This holds your date.' : ''))
                        : self::them('Waiting for '.$cust.' to pay the '.$pay($due).'.');
                }

                return $c ? self::them('Fully paid. See you on '.$b->event_date->format('M j').'!') : self::you('Fully paid. Run the event on '.$b->event_date->format('M j').', then mark it done.');
            case 'completed':
                return self::done('Event done. Thank you!');
        }

        return self::done($b->statusLabel().'.');
    }
}
