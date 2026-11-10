<?php

namespace App\Domain;

use App\Models\Payment;
use App\Models\RentalRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Online payments through PayMongo Checkout (GCash, Maya, cards, QR Ph).
 *
 * Flow: the customer starts a payment → a Payment row is created with status "awaiting" and a hosted
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

    /** Start an online payment: returns ['ok' => true, 'url' => checkout page] or ['ok' => false, 'error' => …]. */
    public static function start(RentalRequest $r, array $data): array
    {
        if (! self::enabled()) {
            return ['ok' => false, 'error' => 'Online payment is not available right now. Please pay by transfer.'];
        }
        if ((float) ($data['amount'] ?? 0) < 20) {
            return ['ok' => false, 'error' => 'Online payments must be at least ₱20.00.'];
        }
        // An earlier unfinished checkout for this request: settle it if it was paid, otherwise close it.
        foreach ($r->payments()->where('status', 'awaiting')->get() as $old) {
            if (self::sync($old) === 'unpaid') {
                self::discard($old);
            }
        }
        $r->load('payments');

        $res = Rentals::submitPayment($r, ['method' => 'online', 'reference' => ''] + $data, 'customer');
        if (! $res['ok']) {
            return $res;
        }
        $p = $res['payment'];
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
                'success_url' => route('rentals.paymongo.return', [$r, $p]),
                'cancel_url' => route('rentals.paymongo.return', [$r, $p, 'cancelled' => 1]),
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

            // the same shape PayMongo returns for a paid checkout session
            return self::settle($p, ['payments' => [['id' => $s['payId'], 'attributes' => [
                'status' => 'paid', 'amount' => (int) round($s['paidAmount'] * 100), 'fee' => (int) round($s['paidAmount'] * 2.5),
                'source' => ['type' => $s['method']],
            ]]]]);
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

        return DB::transaction(function () use ($p, $paid, $a) {
            $p = Payment::lockForUpdate()->find($p->id);
            if (! $p || $p->status !== 'awaiting') {
                return 'done'; // already handled (return URL and webhook can both arrive)
            }
            $received = round(((int) ($a['amount'] ?? 0)) / 100, 2);
            $source = $a['source']['type'] ?? null;
            $p->fill([
                'reference' => (string) $paid['id'],
                'gateway_fee' => isset($a['fee']) ? round(((int) $a['fee']) / 100, 2) : null,
                'date' => today_iso(),
                'notes' => trim(($p->notes ? $p->notes.' · ' : '').'Paid online'.($source ? ' via '.(['paymaya' => 'Maya', 'gcash' => 'GCash', 'card' => 'card', 'qrph' => 'QR Ph', 'grab_pay' => 'GrabPay'][$source] ?? $source) : '')),
            ])->save();

            $why = abs($received - $p->amount) > 0.009 ? 'PayMongo received '.money($received).' instead of '.money($p->amount).'.' : null;
            if (! $why) {
                $res = Rentals::confirmPayment($p, null);
                if ($res['ok']) {
                    return 'verified';
                }
                $why = $res['error'];
            }
            // Money was taken but the payment can't count as-is: hand it to the cashier.
            $p->update(['status' => 'pending', 'notes' => $p->notes.' · Needs review: '.$why]);
            $r = $p->rentalRequest;
            Notifier::notify('cashier', 'cashier', 'Online payment needs review', $p->code.' for '.$r->code.' was paid through PayMongo but could not be verified automatically: '.$why, route('cashier.verification'));
            Notifier::notify($r->customer_id, 'customer', 'Payment received', 'We received your online payment of '.money($received).' for '.$r->code.'. Our cashier will confirm it shortly.', route('rentals.show', $r));

            return 'review';
        });
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

    /** Check the Paymongo-Signature header: "t=<unix>,te=<test sig>,li=<live sig>", HMAC-SHA256 of "t.body". */
    public static function validSignature(string $body, string $header): bool
    {
        $secret = (string) config('services.paymongo.webhook_secret');
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

        return $p ? self::settle($p, (array) ($session['attributes'] ?? [])) : 'unknown';
    }
}
