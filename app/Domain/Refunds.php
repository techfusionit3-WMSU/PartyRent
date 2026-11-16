<?php

namespace App\Domain;

use App\Models\Payment;

/**
 * Refunds to customers (change list C13). A refund is a Payment of type "refund":
 * - pending  → owed, waiting to be sent (by the gateway or by hand by the shop's cashier)
 * - awaiting → asked from Xendit, waiting for it to finish
 * - verified → sent; the booking's paid amount and the supplier payout go down by it (Payouts::breakdown uses
 *              paidRental − refunded).
 *
 * issue(): when the customer paid online, the money goes back through the same gateway — Xendit's Refund API on
 * the paid invoice, or instantly in practice mode (no key, no real money). Bank / cash payments, a key without the
 * refund permission, or a refused refund leave it pending for the cashier, with the reason in the notes.
 */
class Refunds
{
    /** The verified online payment of the request that can carry this refund (largest first). */
    public static function source(Payment $refund): ?Payment
    {
        return Payment::where('rental_request_id', $refund->rental_request_id)->where('status', 'verified')->where('method', 'online')
            ->whereIn('type', Finance::RENTAL_TYPES)->whereIn('gateway', ['xendit', 'paymongo'])->where('amount', '>=', $refund->amount - 0.001)
            ->orderByDesc('amount')->first();
    }

    /** Try to send a pending refund through the gateway. Returns sent | awaiting | manual. */
    public static function issue(Payment $refund): string
    {
        if ($refund->type !== 'refund' || $refund->status !== 'pending') {
            return 'manual';
        }
        $src = self::source($refund);
        if (! $src) {
            return 'manual';
        }
        if ($src->gateway === 'paymongo' && PayMongo::practice()) {
            self::done($refund, 'practice', 'PRACTICE-'.$refund->code, 'Practice refund to the original online payment '.$src->code);

            return 'sent';
        }
        if ($src->gateway !== 'xendit' || ! Xendit::enabled() || ! $src->gateway_ref) {
            return 'manual';
        }
        $res = Xendit::refund($src, $refund);
        if (! $res['ok'] || $res['status'] === 'FAILED') {
            $why = $res['error'] ?? 'refund failed';
            $refund->update(['notes' => trim($refund->notes.' · Xendit refund not possible: '.$why.'. Send it by hand and verify it.')]);
            Notifier::finance($refund->rentalRequest->supplier, 'Refund needs a manual transfer', $refund->code.' ('.money($refund->amount).'): Xendit could not refund it — '.$why.'.', route('supplier.requests.show', $refund->rentalRequest, false));

            return 'manual';
        }
        if ($res['status'] === 'SUCCEEDED') {
            self::done($refund, 'xendit', $res['id'], 'Refunded through Xendit to the original payment '.$src->code);

            return 'sent';
        }
        $refund->update(['status' => 'awaiting', 'method' => 'online', 'gateway' => 'xendit', 'gateway_ref' => $res['id'],
            'notes' => trim($refund->notes.' · Xendit refund '.$res['id'].' in progress')]);

        return 'awaiting';
    }

    /** Refund confirmed by the gateway: verified, customer told. */
    public static function done(Payment $refund, string $gateway, string $ref, string $note): void
    {
        $refund->update(['status' => 'verified', 'method' => 'online', 'gateway' => $gateway, 'gateway_ref' => $ref, 'reference' => $ref,
            'verified_at' => now(), 'notes' => trim($refund->notes.' · '.$note)]);
        $r = $refund->rentalRequest;
        $r->finCache = null;
        Notifier::notify($r->customer_id, 'customer', 'Refund sent', money($refund->amount).' for '.$r->code.' was sent back to your original payment method. It can take a few days to show.', route('rentals.show', $r, false));
        Notifier::audit('Refund sent', $refund->code.' · '.money($refund->amount).' · '.$r->code.' · '.$gateway);
    }

    /** Xendit refund still in progress: ask again. */
    public static function sync(Payment $refund): string
    {
        if ($refund->status !== 'awaiting' || $refund->gateway !== 'xendit' || ! $refund->gateway_ref) {
            return 'done';
        }

        return self::apply($refund, Xendit::refundStatus($refund->gateway_ref));
    }

    public static function apply(Payment $refund, ?string $status): string
    {
        if ($status === 'SUCCEEDED') {
            self::done($refund, 'xendit', $refund->gateway_ref, 'Xendit confirmed the refund');

            return 'sent';
        }
        if ($status === 'FAILED') {
            $refund->update(['status' => 'pending', 'notes' => trim($refund->notes.' · Xendit refund failed. Send it by hand and verify it.')]);
            Notifier::finance($refund->rentalRequest->supplier, 'Refund needs a manual transfer', $refund->code.' ('.money($refund->amount).'): the Xendit refund failed.', route('supplier.requests.show', $refund->rentalRequest, false));

            return 'manual';
        }

        return 'awaiting';
    }

    /** Verified callback "refund.succeeded" / "refund.failed": data.id = Xendit refund id. */
    public static function handleCallback(array $body): string
    {
        $d = (array) ($body['data'] ?? $body);
        $p = Payment::where('type', 'refund')->where('gateway', 'xendit')->where('gateway_ref', (string) ($d['id'] ?? ''))->first();
        if (! $p) {
            return 'unknown';
        }
        $status = strtoupper((string) ($d['status'] ?? ''));
        if ($status === '') {
            $status = str_ends_with((string) ($body['event'] ?? ''), 'succeeded') ? 'SUCCEEDED' : 'FAILED';
        }

        return self::apply($p, $status);
    }
}
