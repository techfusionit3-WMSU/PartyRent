<?php

namespace App\Domain;

use App\Models\EventPayment;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Automatic payment checking (system update 2026-11-23). Nobody at PartyRent checks online payments by hand.
 *
 *   1. Customer      checks out and pays with GCash, Maya or a card on the gateway's page (OnlinePay::start).
 *   2. Gateway       processes the payment and sends PartyRent a webhook (PayMongo / Xendit / practice).
 *   3. PartyRent     verifies the webhook (signature or callback token) before touching the order, then the
 *                    payment becomes Verified, a receipt is issued and the order's payment status updates
 *                    (Partially Paid / Fully Paid). The money sits in PartyRent's collection account.
 *   4. Seller        is paid later by Payouts, after commission (see Payouts / Admin → Supplier Payouts).
 *
 * Webhooks can be lost (and never reach a laptop on localhost), so run() also asks the gateway about every
 * checkout still open: every minute from the scheduler (partyrent:check-payments), when admin and cashier
 * pages load, and from Admin → Online Payments → "Check now". A checkout that stays unpaid past the
 * gateway's 24-hour limit is closed. Every step is written to payment_transactions.
 */
class PaymentChecks
{
    /** Open checkouts older than this are closed (the gateway invoice / session lasts a day). */
    public const EXPIRE_HOURS = 24;

    /** Page loads run the check at most this often. */
    public const PAGE_EVERY_SECONDS = 60;

    private const LAST_KEY = 'payment-checks:last';

    /** Who is talking to the gateway right now (webhook | return | auto_check | manual_check | checkout). */
    private static string $source = 'checkout';

    /** The webhook event being handled; stored on the first transaction it writes (idempotency key). */
    private static ?string $eventId = null;

    /** Has this gateway event already been processed? (webhook idempotency) */
    public static function seen(string $eventId): bool
    {
        return PaymentTransaction::where('event_id', $eventId)->exists();
    }

    /**
     * Handle one webhook event exactly once. A repeat of an event already processed returns 'duplicate' and
     * changes nothing; the event id is stored (unique) on the transaction it writes.
     */
    public static function once(string $eventId, callable $fn): mixed
    {
        $eventId = mb_substr($eventId, 0, 120);
        if ($eventId !== '' && self::seen($eventId)) {
            return 'duplicate';
        }
        $prev = self::$eventId;
        self::$eventId = $eventId !== '' ? $eventId : null;
        try {
            $res = $fn();
            if (self::$eventId && ! self::seen(self::$eventId)) { // nothing was logged (e.g. still unpaid): remember the event anyway
                self::log(['result' => 'ignored', 'message' => 'Webhook received: '.(is_string($res) ? $res : 'handled').'.']);
            }

            return $res;
        } finally {
            self::$eventId = $prev;
        }
    }

    /** Run $fn with $source recorded on every transaction it logs. */
    public static function via(string $source, callable $fn): mixed
    {
        $prev = self::$source;
        self::$source = $source;
        try {
            return $fn();
        } finally {
            self::$source = $prev;
        }
    }

    public static function source(): string
    {
        return self::$source;
    }

    /** Record one gateway message. Never breaks the payment itself if logging fails. */
    public static function log(array $row): ?PaymentTransaction
    {
        try {
            $row += ['source' => self::$source, 'verified' => true];
            if (self::$eventId && ! isset($row['event_id'])) { // the first row of a webhook carries its event id
                $row['event_id'] = self::$eventId;
                self::$eventId = null;
            }

            return PaymentTransaction::create($row);
        } catch (\Throwable $e) {
            Log::warning('Could not record payment transaction: '.$e->getMessage());

            return null;
        }
    }

    public static function logRental(Payment $p, string $result, array $extra = []): ?PaymentTransaction
    {
        return self::log($extra + [
            'gateway' => $p->gateway, 'kind' => 'rental', 'payment_id' => $p->exists ? $p->id : null, 'payment_code' => $p->code,
            'order_code' => $p->rentalRequest?->code, 'gateway_ref' => $p->gateway_ref, 'amount' => $p->amount, 'result' => $result,
        ]);
    }

    public static function logEvent(EventPayment $p, string $result, array $extra = []): ?PaymentTransaction
    {
        return self::log($extra + [
            'gateway' => $p->gateway, 'kind' => 'event', 'event_payment_id' => $p->id, 'payment_code' => $p->code,
            'order_code' => $p->booking?->code, 'gateway_ref' => $p->gateway_ref, 'amount' => $p->amount, 'result' => $result,
        ]);
    }

    /**
     * Ask the gateway about every open checkout and settle the paid ones.
     * Returns ['checked', 'paid', 'review', 'expired', 'error'].
     */
    public static function run(string $source = 'auto_check'): array
    {
        $n = ['checked' => 0, 'paid' => 0, 'review' => 0, 'expired' => 0, 'error' => 0];

        self::via($source, function () use (&$n, $source) {
            foreach (Payment::where('status', 'awaiting')->whereNotNull('gateway')->whereIn('type', Payment::INFLOW)->with('rentalRequest')->orderBy('id')->get() as $p) {
                $n['checked']++;
                $state = OnlinePay::sync($p);
                Payment::whereKey($p->id)->update(['checked_at' => now()]);
                if ($state === 'verified') {
                    $n['paid']++;
                } elseif ($state === 'review') {
                    $n['review']++;
                } elseif ($state === 'error') {
                    $n['error']++;
                    if ($source === 'manual_check') {
                        self::logRental($p, 'error', ['verified' => false, 'message' => 'Could not reach '.OnlinePay::name($p->gateway).'.']);
                    }
                }
                if ($state === 'unpaid' && ($p->submitted_at ?? $p->created_at)?->lt(now()->subHours(self::EXPIRE_HOURS))) {
                    self::logRental($p, 'expired', ['message' => 'Not paid within '.self::EXPIRE_HOURS.' hours; checkout closed.']);
                    OnlinePay::discard($p);
                    $n['expired']++;
                }
            }

            foreach (EventPayment::where('status', 'due')->where('gateway', 'xendit')->whereNotNull('gateway_ref')->with('booking')->orderBy('id')->get() as $p) {
                $n['checked']++;
                $state = EventOnlinePay::sync($p);
                EventPayment::whereKey($p->id)->update(['checked_at' => now()]);
                match ($state) {
                    'paid' => $n['paid']++,
                    'review' => $n['review']++,
                    'error' => $n['error']++,
                    default => null,
                };
            }
        });

        Cache::put(self::LAST_KEY, ['at' => now()->toIso8601String(), 'source' => $source] + $n, now()->addDays(7));

        return $n;
    }

    /** Run the check from a page load, at most once a minute for the whole site. */
    public static function runIfDue(): ?array
    {
        if (! Cache::add('payment-checks:lock', 1, self::PAGE_EVERY_SECONDS)) {
            return null;
        }
        try {
            return self::run('auto_check');
        } catch (\Throwable $e) {
            Log::warning('Automatic payment check failed: '.$e->getMessage());

            return null;
        }
    }

    /** ['at' => ISO time, 'source', 'checked', 'paid', …] of the last check, or null. */
    public static function lastRun(): ?array
    {
        return Cache::get(self::LAST_KEY);
    }

    /* ---------- practice gateway: the same signed webhook a real gateway sends ---------- */

    /** Sign a practice notification like PayMongo does ("t=…,te=…"), so it goes through the same verification. */
    public static function practiceSignature(string $body): string
    {
        $t = (string) time();

        return 't='.$t.',te='.hash_hmac('sha256', $t.'.'.$body, PayMongo::webhookSecret()).',li=';
    }
}
