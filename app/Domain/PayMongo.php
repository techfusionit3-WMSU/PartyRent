<?php

namespace App\Domain;

use App\Models\Payment;
use App\Models\RentalRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Online payments through PayMongo Checkout (GCash, Maya, cards, QR Ph).
 *
 * Flow (see OnlinePay): the customer starts a payment → a Payment row is created with status "awaiting" and a hosted
 * checkout session → the customer pays on PayMongo's page → PayMongo sends them back (return URL) and/or
 * calls the webhook → we read the session, and if it is paid the payment is verified automatically
 * (receipt issued, no cashier step). The money lands in PartyRent's PayMongo balance; supplier payouts
 * work exactly as before. If it can't be auto-verified (e.g. it now exceeds the balance) it goes to the
 * cashier's verification queue as "pending" with a note.
 */
class PayMongo
{
    /** Practice payment methods (same codes PayMongo uses for `source.type`). */
    public const PRACTICE_METHODS = ['gcash' => 'GCash', 'paymaya' => 'Maya', 'card' => 'Card', 'qrph' => 'QR Ph'];

    public static function enabled(): bool
    {
        return (string) config('services.paymongo.secret_key') !== '' || self::practice();
    }

    /**
     * Practice mode (PAYMONGO_FAKE=true, never in production): "Pay online" opens a built-in checkout
     * page instead of PayMongo's. Everything after it (return URL, automatic verification, receipts,
     * notifications) runs the same code as the real thing. Session state lives in the cache.
     */
    public static function practice(): bool
    {
        return (bool) config('services.paymongo.fake') && ! app()->isProduction();
    }

    public static function liveMode(): bool
    {
        return ! self::practice() && str_starts_with((string) config('services.paymongo.secret_key'), 'sk_live_');
    }

    public static function practiceKey(string $ref): string
    {
        return 'paymongo-practice:'.$ref;
    }

    /** The practice "checkout session" for a reference, or null when it expired / never existed. */
    public static function practiceSession(string $ref): ?array
    {
        return str_starts_with($ref, 'cs_practice_') ? Cache::get(self::practiceKey($ref)) : null;
    }

    public static function practiceUpdate(string $ref, array $changes): void
    {
        if ($s = self::practiceSession($ref)) {
            Cache::put(self::practiceKey($ref), array_merge($s, $changes), now()->addDay());
        }
    }

    private static function http()
    {
        return Http::withBasicAuth((string) config('services.paymongo.secret_key'), '')
            ->acceptJson()->asJson()->timeout(20)->baseUrl(rtrim((string) config('services.paymongo.base_url'), '/'));
    }

    private static function errorText($res): string
    {
        $e = $res->json('errors.0');

        return $e ? ($e['detail'] ?? $e['code'] ?? 'unknown error') : 'HTTP '.$res->status();
    }

    /** Create the checkout session (or practice session) for an awaiting payment made by OnlinePay::start. */
    public static function open(RentalRequest $r, Payment $p): array
    {
        $u = $r->customer;
        if (self::practice()) {
            $ref = 'cs_practice_'.Str::lower(Str::random(16));
            Cache::put(self::practiceKey($ref), ['payment' => $p->code, 'request' => $r->code, 'amount' => (float) $p->amount, 'label' => $p->typeLabel().' · '.$r->code, 'status' => 'unpaid'], now()->addDay());
            $p->update(['gateway_ref' => $ref, 'gateway_url' => route('paymongo.practice', $ref)]);

            return ['ok' => true, 'url' => $p->gateway_url, 'payment' => $p];
        }
        try {
            $api = self::http()->post('/checkout_sessions', ['data' => ['attributes' => array_filter([
                'line_items' => [['currency' => 'PHP', 'amount' => (int) round($p->amount * 100), 'name' => $p->typeLabel().' · '.$r->code, 'quantity' => 1]],
                'payment_method_types' => config('services.paymongo.methods'),
                'description' => Settings::get('platformName').' '.$r->code.' · '.$r->event_name,
                'reference_number' => $p->code,
                'success_url' => route('rentals.online.return', [$r, $p]),
                'cancel_url' => route('rentals.online.return', [$r, $p, 'cancelled' => 1]),
                'send_email_receipt' => true,
                'show_description' => true,
                'show_line_items' => true,
                'billing' => array_filter(['name' => $u->name, 'email' => $u->email, 'phone' => $u->phone ?? null]),
                'metadata' => ['payment' => $p->code, 'request' => $r->code],
            ])]]);
        } catch (\Throwable $e) {
            $p->delete();
            Log::warning('PayMongo checkout failed: '.$e->getMessage());

            return ['ok' => false, 'error' => 'Could not reach PayMongo. Please try again or pay by transfer.'];
        }
        if (! $api->successful() || ! $api->json('data.attributes.checkout_url')) {
            $p->delete();
            Log::warning('PayMongo checkout refused: '.$api->body());

            return ['ok' => false, 'error' => 'PayMongo could not start the payment ('.self::errorText($api).').'];
        }
        $p->update(['gateway_ref' => $api->json('data.id'), 'gateway_url' => $api->json('data.attributes.checkout_url')]);

        return ['ok' => true, 'url' => $p->gateway_url, 'payment' => $p];
    }

    /** Ask PayMongo for the session and settle it. Returns verified | review | unpaid | done | error. */
    public static function sync(Payment $p): string
    {
        if ($p->status !== 'awaiting') {
            return 'done';
        }
        if (! $p->gateway_ref || ! self::enabled()) {
            return 'error';
        }
        if (str_starts_with($p->gateway_ref, 'cs_practice_')) {
            $s = self::practiceSession($p->gateway_ref);
            if (! $s || $s['status'] !== 'paid') {
                return 'unpaid';
            }

            return self::settle($p, self::practiceAttributes($s));
        }
        try {
            $res = self::http()->get('/checkout_sessions/'.$p->gateway_ref);
        } catch (\Throwable $e) {
            Log::warning('PayMongo sync failed: '.$e->getMessage());

            return 'error';
        }
        if (! $res->successful()) {
            return 'error';
        }

        return self::settle($p, (array) $res->json('data.attributes'));
    }

    /** Settle an awaiting payment from a checkout session's attributes (from the API or a signed webhook). */
    public static function settle(Payment $p, array $session): string
    {
        $paid = null;
        foreach ($session['payments'] ?? [] as $pay) {
            if (($pay['attributes']['status'] ?? '') === 'paid') {
                $paid = $pay;
                break;
            }
        }
        if (! $paid) {
            return 'unpaid';
        }
        $a = $paid['attributes'];
        $source = $a['source']['type'] ?? null;

        return OnlinePay::settle(
            $p,
            (string) $paid['id'],
            round(((int) ($a['amount'] ?? 0)) / 100, 2),
            isset($a['fee']) ? round(((int) $a['fee']) / 100, 2) : null,
            $source ? (['paymaya' => 'Maya', 'gcash' => 'GCash', 'card' => 'card', 'qrph' => 'QR Ph', 'grab_pay' => 'GrabPay'][$source] ?? $source) : null,
        );
    }

    /** Close an unpaid checkout so it can't be paid later, and drop the row (no money moved). */
    public static function discard(Payment $p): void
    {
        if ($p->status !== 'awaiting') {
            return;
        }
        if ($p->gateway_ref && str_starts_with($p->gateway_ref, 'cs_practice_')) {
            Cache::forget(self::practiceKey($p->gateway_ref));
        } elseif ($p->gateway_ref && self::enabled()) {
            try {
                self::http()->post('/checkout_sessions/'.$p->gateway_ref.'/expire');
            } catch (\Throwable) {
                // the session also expires on its own
            }
        }
        $p->delete();
    }

    /** The same shape PayMongo returns for a paid checkout session, from a practice session. */
    public static function practiceAttributes(array $s): array
    {
        return ['payments' => [['id' => $s['payId'], 'attributes' => [
            'status' => 'paid', 'amount' => (int) round($s['paidAmount'] * 100), 'fee' => (int) round($s['paidAmount'] * 2.5),
            'source' => ['type' => $s['method']],
        ]]]];
    }

    /**
     * The practice gateway's webhook: once the customer pays on the practice page it sends PartyRent the same
     * signed checkout_session.payment.paid event PayMongo would, and it goes through the same verification.
     */
    public static function practiceNotify(string $ref): string
    {
        $s = self::practiceSession($ref);
        if (! $s || $s['status'] !== 'paid') {
            return 'unpaid';
        }
        $body = json_encode(['data' => ['id' => 'evt_practice_'.Str::lower(Str::random(12)), 'attributes' => [
            'type' => 'checkout_session.payment.paid', 'livemode' => false,
            'data' => ['id' => $ref, 'type' => 'checkout_session', 'attributes' => self::practiceAttributes($s)],
        ]]]);

        return self::receiveWebhook($body, PaymentChecks::practiceSignature($body)) ?? 'rejected';
    }

    /** Secret the webhook signature is checked with. Practice mode signs with a local secret instead. */
    public static function webhookSecret(): string
    {
        $secret = (string) config('services.paymongo.webhook_secret');
        if ($secret === '' && self::practice()) {
            $secret = 'whsk_practice_'.substr(hash('sha256', (string) config('app.key')), 0, 24);
        }

        return $secret;
    }

    /**
     * A webhook arrived: verify the signature first, and only then let it change a payment.
     * Returns the result (verified | review | done | unpaid | unknown | ignored) or null when the signature is wrong.
     */
    public static function receiveWebhook(string $body, string $header): ?string
    {
        if (! self::validSignature($body, $header)) {
            $event = (array) json_decode($body, true);
            PaymentChecks::via('webhook', fn () => PaymentChecks::log([
                'gateway' => 'paymongo', 'result' => 'rejected', 'verified' => false,
                'gateway_ref' => mb_substr((string) ($event['data']['attributes']['data']['id'] ?? ''), 0, 80) ?: null,
                'message' => 'Webhook refused: the Paymongo-Signature did not match.',
            ]));

            return null;
        }

        $event = (array) json_decode($body, true);

        return PaymentChecks::via('webhook', fn () => PaymentChecks::once('paymongo:'.($event['data']['id'] ?? ''), fn () => self::handleEvent($event)));
    }

    /** Check the Paymongo-Signature header: "t=<unix>,te=<test sig>,li=<live sig>", HMAC-SHA256 of "t.body". */
    public static function validSignature(string $body, string $header): bool
    {
        $secret = self::webhookSecret();
        if ($secret === '' || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k] = $v;
        }
        if (empty($parts['t'])) {
            return false;
        }
        $expected = hash_hmac('sha256', $parts['t'].'.'.$body, $secret);
        $given = (string) ($parts[self::liveMode() ? 'li' : 'te'] ?? '');

        return $given !== '' && hash_equals($expected, $given);
    }

    /** Handle a verified webhook event. Only paid checkout sessions matter to us. */
    public static function handleEvent(array $event): string
    {
        $type = $event['data']['attributes']['type'] ?? '';
        $session = $event['data']['attributes']['data'] ?? [];
        if ($type !== 'checkout_session.payment.paid' || empty($session['id'])) {
            return 'ignored';
        }
        $p = Payment::where('gateway', 'paymongo')->where('gateway_ref', $session['id'])->first();
        if (! $p) {
            PaymentChecks::log(['gateway' => 'paymongo', 'result' => 'ignored', 'gateway_ref' => mb_substr((string) $session['id'], 0, 80), 'message' => "Paid checkout session is not one of PartyRent's open payments."]);

            return 'unknown';
        }

        return self::settle($p, (array) ($session['attributes'] ?? []));
    }
}
