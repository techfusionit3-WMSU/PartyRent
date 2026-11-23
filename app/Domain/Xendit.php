<?php

namespace App\Domain;

use App\Models\Payment;
use App\Models\RentalRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Online payments through Xendit's hosted Invoice page (GCash, Maya, GrabPay, ShopeePay, QR Ph, cards,
 * online banking, over-the-counter…). Which channels show is set in the Xendit dashboard, or narrowed
 * with XENDIT_METHODS.
 *
 * Flow: OnlinePay::start creates an "awaiting" Payment → open() creates an invoice (external_id = payment
 * code) → the customer pays on Xendit's page → Xendit sends them back to the return URL and/or calls the
 * invoice webhook (checked with the x-callback-token header) → the invoice is PAID → OnlinePay::settle
 * verifies it automatically. Money lands in PartyRent's Xendit balance; supplier payouts are unchanged.
 */
class Xendit
{
    /** payment_channel codes → names shown in notes. */
    public const CHANNELS = [
        'GCASH' => 'GCash', 'PAYMAYA' => 'Maya', 'GRABPAY' => 'GrabPay', 'SHOPEEPAY' => 'ShopeePay', 'QRPH' => 'QR Ph',
        'CREDIT_CARD' => 'card', 'DD_BPI' => 'BPI online', 'DD_UBP' => 'UnionBank online', 'DD_RCBC' => 'RCBC online',
        'DD_BDO_EPAY' => 'BDO online', '7ELEVEN' => '7-Eleven', 'CEBUANA' => 'Cebuana', 'BILLEASE' => 'BillEase',
    ];

    public static function enabled(): bool
    {
        return (string) config('services.xendit.secret_key') !== '';
    }

    public static function liveMode(): bool
    {
        return str_starts_with((string) config('services.xendit.secret_key'), 'xnd_production_');
    }

    public static function http()
    {
        return Http::withBasicAuth((string) config('services.xendit.secret_key'), '')
            ->acceptJson()->asJson()->timeout(20)->baseUrl(rtrim((string) config('services.xendit.base_url'), '/'));
    }

    private static function errorText($res): string
    {
        return (string) ($res->json('message') ?? $res->json('error_code') ?? 'HTTP '.$res->status());
    }

    /** Create the Xendit invoice for an awaiting payment. On failure the payment row is removed. */
    public static function open(RentalRequest $r, Payment $p): array
    {
        $u = $r->customer;
        $name = $p->typeLabel().' · '.$r->code;
        try {
            $api = self::http()->post('/v2/invoices', array_filter([
                'external_id' => $p->code,
                'amount' => round((float) $p->amount, 2),
                'currency' => 'PHP',
                'description' => Settings::get('platformName').' '.$r->code.' · '.$r->event_name.' · '.$p->typeLabel(),
                'invoice_duration' => 86400,
                'customer' => array_filter(['given_names' => $u->name, 'email' => filter_var($u->email, FILTER_VALIDATE_EMAIL) ? $u->email : null]),
                'items' => [['name' => $name, 'quantity' => 1, 'price' => round((float) $p->amount, 2)]],
                'payment_methods' => config('services.xendit.methods') ?: null,
                'success_redirect_url' => route('rentals.online.return', [$r, $p]),
                'failure_redirect_url' => route('rentals.online.return', [$r, $p, 'cancelled' => 1]),
                'metadata' => ['payment' => $p->code, 'request' => $r->code],
            ]));
        } catch (\Throwable $e) {
            $p->delete();
            Log::warning('Xendit invoice failed: '.$e->getMessage());

            return ['ok' => false, 'error' => 'Could not reach Xendit. Please try again or pay by transfer.'];
        }
        if (! $api->successful() || ! $api->json('invoice_url')) {
            $p->delete();
            Log::warning('Xendit invoice refused: '.$api->body());

            return ['ok' => false, 'error' => 'Xendit could not start the payment ('.self::errorText($api).').'];
        }
        $p->update(['gateway_ref' => $api->json('id'), 'gateway_url' => $api->json('invoice_url')]);

        return ['ok' => true, 'url' => $p->gateway_url, 'payment' => $p];
    }

    public static function sync(Payment $p): string
    {
        if ($p->status !== 'awaiting') {
            return 'done';
        }
        if (! $p->gateway_ref || ! self::enabled()) {
            return 'error';
        }
        try {
            $res = self::http()->get('/v2/invoices/'.$p->gateway_ref);
        } catch (\Throwable $e) {
            Log::warning('Xendit sync failed: '.$e->getMessage());

            return 'error';
        }

        return $res->successful() ? self::settle($p, (array) $res->json()) : 'error';
    }

    /** Settle an awaiting payment from an invoice (from the API or a verified callback). */
    public static function settle(Payment $p, array $inv): string
    {
        if (! in_array($inv['status'] ?? '', ['PAID', 'SETTLED'], true)) {
            return 'unpaid';
        }
        $channel = (string) ($inv['payment_channel'] ?? '');
        $fee = $inv['fees_paid_amount'] ?? null;

        return OnlinePay::settle(
            $p,
            (string) ($inv['payment_id'] ?? $inv['id'] ?? $p->gateway_ref),
            round((float) ($inv['paid_amount'] ?? $inv['amount'] ?? 0), 2),
            $fee !== null ? round((float) $fee, 2) : null,
            $channel !== '' ? (self::CHANNELS[$channel] ?? $channel) : null,
        );
    }

    public static function discard(Payment $p): void
    {
        if ($p->status !== 'awaiting') {
            return;
        }
        if ($p->gateway_ref && self::enabled()) {
            try {
                self::http()->post('/invoices/'.$p->gateway_ref.'/expire!');
            } catch (\Throwable) {
                // the invoice also expires on its own after a day
            }
        }
        $p->delete();
    }

    /* ---------- refunds (change list C13) ---------- */

    /**
     * Refund part or all of a paid invoice: POST /refunds {invoice_id, reference_id, amount, reason}.
     * Returns ['ok', 'id', 'status' => SUCCEEDED|PENDING|FAILED, 'error'].
     */
    public static function refund(Payment $source, Payment $refund, string $reason = 'OTHERS'): array
    {
        try {
            $res = self::http()->withHeaders(['Idempotency-key' => 'refund-'.$refund->code])->post('/refunds', [
                'invoice_id' => $source->gateway_ref, 'reference_id' => $refund->code, 'amount' => round((float) $refund->amount, 2), 'currency' => 'PHP',
                'reason' => $reason, 'metadata' => ['payment' => $source->code, 'refund' => $refund->code],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Xendit refund failed: '.$e->getMessage());

            return ['ok' => false, 'error' => 'Could not reach Xendit'];
        }
        if (! $res->successful()) {
            Log::warning('Xendit refund refused: '.$res->body());

            return ['ok' => false, 'error' => self::errorText($res), 'code' => $res->json('error_code'), 'http' => $res->status()];
        }

        return ['ok' => true, 'id' => (string) $res->json('id'), 'status' => strtoupper((string) $res->json('status'))];
    }

    /** Current status of a refund (SUCCEEDED | PENDING | FAILED) or null when Xendit cannot be asked. */
    public static function refundStatus(string $id): ?string
    {
        try {
            $res = self::http()->get('/refunds/'.$id);
        } catch (\Throwable) {
            return null;
        }

        return $res->successful() ? strtoupper((string) $res->json('status')) : null;
    }

    /**
     * Does the secret key have the Refunds permission? Lists refunds (read): a key without it gets 401/403.
     * true | false, or null when Xendit could not be reached. Creating a refund also needs WRITE; a refused
     * create leaves the refund for the cashier with Xendit's reason in the notes.
     */
    public static function canRefund(): ?bool
    {
        if (! self::enabled()) {
            return false;
        }
        try {
            $res = self::http()->get('/refunds', ['limit' => 1]);
        } catch (\Throwable) {
            return null;
        }
        if (in_array($res->status(), [401, 403], true)) {
            return false;
        }

        return $res->successful() ? true : null;
    }

    /** Xendit sends the account's callback verification token in the x-callback-token header. */
    public static function validToken(string $given): bool
    {
        $token = (string) config('services.xendit.callback_token');

        return $token !== '' && $given !== '' && hash_equals($token, $given);
    }

    /** Handle a verified invoice callback. */
    public static function handleCallback(array $inv): string
    {
        if (empty($inv['id'])) {
            return 'ignored';
        }
        $p = Payment::where('gateway', 'xendit')->where('gateway_ref', $inv['id'])->first();
        if (! $p) { // an event company's installment (EventOnlinePay) has its own table
            $res = EventOnlinePay::handleCallback($inv);
            if ($res === null) {
                PaymentChecks::log(['gateway' => 'xendit', 'result' => 'ignored', 'gateway_ref' => mb_substr((string) $inv['id'], 0, 80), 'message' => "Invoice is not one of PartyRent's open payments."]);
            }

            return $res ?? 'unknown';
        }
        if (($inv['external_id'] ?? $p->code) !== $p->code) {
            PaymentChecks::logRental($p, 'ignored', ['verified' => false, 'message' => 'Invoice reference does not match '.$p->code.'.']);

            return 'unknown';
        }

        return self::settle($p, $inv);
    }
}
