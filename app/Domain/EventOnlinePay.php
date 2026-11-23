<?php

namespace App\Domain;

use App\Models\EventPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "Pay online" for an event booking's installment. Uses Xendit (hosted invoice) when it is configured,
 * otherwise the built-in practice checkout (PAYMONGO_FAKE, never in production). The gateway confirms the
 * payment, so it is marked paid without the business checking a proof; the money is with PartyRent until an
 * admin sends it to the business (Admin → Event bookings → To send).
 */
class EventOnlinePay
{
    public static function mode(): ?string
    {
        if (Xendit::enabled()) {
            return 'xendit';
        }

        return PayMongo::practice() ? 'practice' : null;
    }

    public static function enabled(): bool
    {
        return self::mode() !== null;
    }

    private static function fail(string $m): array { return ['ok' => false, 'error' => $m]; }

    /** Start paying one installment online: ['ok' => true, 'url' => checkout page]. */
    public static function start(EventPayment $p): array
    {
        $b = $p->booking;
        $mode = self::mode();
        if (! $mode) {
            return self::fail('Online payment is not available right now. Please pay by transfer.');
        }
        if ($p->status !== 'due' || ! in_array($b->status, ['booked', 'confirmed'], true)) {
            return self::fail('This payment cannot be paid right now.');
        }
        if ($p->amount < 20) {
            return self::fail('Online payments must be at least ₱20.00.');
        }
        if ($mode === 'practice') {
            $p->update(['gateway' => 'practice', 'gateway_ref' => 'PRACTICE-'.$p->code, 'gateway_url' => null]);

            return ['ok' => true, 'url' => route('events.online.practice', [$b, $p])];
        }
        if ($p->gateway === 'xendit' && $p->gateway_url) { // an invoice is already open: use it
            return ['ok' => true, 'url' => $p->gateway_url];
        }
        try {
            $api = Xendit::http()->post('/v2/invoices', array_filter([
                'external_id' => $p->code,
                'amount' => round($p->amount, 2),
                'currency' => 'PHP',
                'description' => Settings::get('platformName').' '.$b->code.' · '.$b->supplier->name.' · '.$p->label,
                'invoice_duration' => 86400,
                'customer' => array_filter(['given_names' => $b->customer->name, 'email' => filter_var($b->customer->email, FILTER_VALIDATE_EMAIL) ? $b->customer->email : null]),
                'items' => [['name' => $p->label.' · '.$b->code, 'quantity' => 1, 'price' => round($p->amount, 2)]],
                'payment_methods' => config('services.xendit.methods') ?: null,
                'success_redirect_url' => route('events.online.return', [$b, $p]),
                'failure_redirect_url' => route('events.online.return', [$b, $p, 'cancelled' => 1]),
                'metadata' => ['eventPayment' => $p->code, 'booking' => $b->code],
            ]));
        } catch (\Throwable $e) {
            Log::warning('Xendit event invoice failed: '.$e->getMessage());

            return self::fail('Could not reach Xendit. Please try again or pay by transfer.');
        }
        if (! $api->successful() || ! $api->json('invoice_url')) {
            Log::warning('Xendit event invoice refused: '.$api->body());

            return self::fail('Xendit could not start the payment. Please pay by transfer.');
        }
        $p->update(['gateway' => 'xendit', 'gateway_ref' => $api->json('id'), 'gateway_url' => $api->json('invoice_url')]);

        return ['ok' => true, 'url' => $p->gateway_url];
    }

    /**
     * Practice gateway for event payments: sends the same kind of signed notification a real gateway does,
     * verifies the signature, and only then marks the installment paid.
     */
    public static function practiceNotify(EventPayment $p): string
    {
        $body = json_encode(['event' => 'event_payment.paid', 'external_id' => $p->code, 'id' => $p->gateway_ref, 'paid_amount' => (float) $p->amount, 'payment_channel' => 'practice checkout']);
        $header = PaymentChecks::practiceSignature($body);

        return PaymentChecks::via('webhook', function () use ($p, $body, $header) {
            if (! PayMongo::validSignature($body, $header)) {
                PaymentChecks::logEvent($p, 'rejected', ['verified' => false, 'message' => 'Practice notification refused: bad signature.']);

                return 'rejected';
            }
            $n = (array) json_decode($body, true);
            if (($n['external_id'] ?? '') !== $p->code) {
                return 'unknown';
            }

            return self::settle($p, (string) $n['id'], (float) $n['paid_amount'], (string) $n['payment_channel']);
        });
    }

    /** Ask Xendit whether the open invoice was paid. Returns paid | unpaid | done | error. */
    public static function sync(EventPayment $p): string
    {
        if ($p->status === 'paid') {
            return 'done';
        }
        if ($p->gateway !== 'xendit' || ! $p->gateway_ref || ! Xendit::enabled()) {
            return 'error';
        }
        try {
            $res = Xendit::http()->get('/v2/invoices/'.$p->gateway_ref);
        } catch (\Throwable $e) {
            return 'error';
        }

        return $res->successful() ? self::fromInvoice($p, (array) $res->json()) : 'error';
    }

    /** A Xendit invoice (API or verified callback) for an event payment. */
    public static function fromInvoice(EventPayment $p, array $inv): string
    {
        if (! in_array($inv['status'] ?? '', ['PAID', 'SETTLED'], true)) {
            return 'unpaid';
        }
        $channel = (string) ($inv['payment_channel'] ?? '');

        return self::settle($p, (string) ($inv['payment_id'] ?? $inv['id'] ?? $p->gateway_ref), round((float) ($inv['paid_amount'] ?? $inv['amount'] ?? 0), 2), $channel !== '' ? (Xendit::CHANNELS[$channel] ?? $channel) : 'Xendit');
    }

    /** Webhook: is this invoice one of ours? Returns null when it is not an event payment. */
    public static function handleCallback(array $inv): ?string
    {
        $p = EventPayment::where('gateway', 'xendit')->where('gateway_ref', $inv['id'] ?? '')->first();
        if (! $p || ($inv['external_id'] ?? '') !== $p->code) {
            return null;
        }

        return self::fromInvoice($p, $inv);
    }

    /** The gateway took the money: mark the installment paid (safe to call twice). */
    public static function settle(EventPayment $p, string $ref, float $received, string $via): string
    {
        $res = DB::transaction(function () use ($p, $ref, $received, $via) {
            $p = EventPayment::lockForUpdate()->find($p->id);
            if (! $p || $p->status === 'paid') {
                return 'done';
            }
            if (abs($received - $p->amount) > 0.009) { // money moved but not the right amount: let the business check it
                $p->update(['status' => 'pending', 'method' => 'online', 'reference' => $ref, 'submitted_at' => now(), 'reject_reason' => null]);

                return 'review';
            }
            $p->update(['method' => 'online', 'reference' => $ref.' ('.$via.')', 'submitted_at' => now()]);

            return 'paid';
        });
        $tx = ['gateway_payment_id' => mb_substr($ref, 0, 80), 'amount' => $received, 'channel' => mb_substr($via, 0, 40)];
        if ($res === 'paid') {
            $p->refresh()->update(['status' => 'pending']); // confirmPayment() expects a pending payment
            Events::confirmPayment($p, null, true);
            PaymentChecks::logEvent($p->fresh(), 'paid', $tx + ['message' => 'Verified automatically · '.$p->booking->code.'.']);
        } elseif ($res === 'review') {
            PaymentChecks::logEvent($p->fresh(), 'review', $tx + ['message' => 'Amount differs from '.money($p->amount).'; the business checks it.']);
            Notifier::notify($p->booking->supplier->owner, 'supplier', 'Online payment to check · '.$p->booking->code, 'An online payment of '.money($received).' came in for the '.mb_strtolower($p->label).' of '.money($p->amount).'. Please check it.', route('business.bookings.show', $p->booking, false));
        }

        return $res;
    }
}
