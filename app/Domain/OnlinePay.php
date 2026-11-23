<?php

namespace App\Domain;

use App\Models\Payment;
use App\Models\RentalRequest;
use Illuminate\Support\Facades\DB;

/**
 * "Pay online": picks the gateway and holds the steps every gateway shares.
 *
 * Gateways: Xendit (hosted Invoice page) and PayMongo (Checkout, plus the built-in practice checkout).
 * New payments use Xendit when XENDIT_SECRET_KEY is set (unless PAYMENT_GATEWAY=paymongo), otherwise
 * PayMongo / practice mode. A payment remembers its gateway, so checkouts already started keep working
 * after a switch.
 */
class OnlinePay
{
    public const NAMES = ['xendit' => 'Xendit', 'paymongo' => 'PayMongo'];

    /** The gateway new online payments go through, or null when "Pay online" is off. */
    public static function gateway(): ?string
    {
        $prefer = (string) config('services.online.gateway');
        if (Xendit::enabled() && $prefer !== 'paymongo') {
            return 'xendit';
        }
        if (PayMongo::enabled()) {
            return 'paymongo';
        }

        return Xendit::enabled() ? 'xendit' : null;
    }

    public static function enabled(): bool
    {
        return self::gateway() !== null;
    }

    /** True when "Pay online" opens the built-in practice checkout (no real money). */
    public static function practice(): bool
    {
        return self::gateway() === 'paymongo' && PayMongo::practice();
    }

    public static function name(?string $gateway = null): string
    {
        return self::NAMES[$gateway ?? self::gateway() ?? ''] ?? 'the payment gateway';
    }

    /** Start an online payment: ['ok' => true, 'url' => hosted checkout page] or ['ok' => false, 'error' => …]. */
    public static function start(RentalRequest $r, array $data): array
    {
        $gw = self::gateway();
        if (! $gw) {
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

        $res = Rentals::submitPayment($r, ['method' => 'online', 'reference' => '', 'gateway' => $gw] + $data, 'customer');
        if (! $res['ok']) {
            return $res;
        }

        return $gw === 'xendit' ? Xendit::open($r, $res['payment']) : PayMongo::open($r, $res['payment']);
    }

    /** Ask the payment's gateway whether it was paid and settle it. Returns verified | review | unpaid | done | error. */
    public static function sync(Payment $p): string
    {
        return match ($p->gateway) {
            'xendit' => Xendit::sync($p),
            'paymongo' => PayMongo::sync($p),
            default => $p->status === 'awaiting' ? 'error' : 'done',
        };
    }

    /** Close an unpaid checkout so it can't be paid later, and drop the row (no money moved). */
    public static function discard(Payment $p): void
    {
        match ($p->gateway) {
            'xendit' => Xendit::discard($p),
            'paymongo' => PayMongo::discard($p),
            default => null,
        };
    }

    /**
     * The gateway says this payment was paid: verify it automatically, or hand it to the cashier when it
     * can't count as-is (different amount, exceeds the balance…). Safe to call twice (return URL + webhook).
     */
    public static function settle(Payment $p, string $gatewayPaymentId, float $received, ?float $fee, ?string $via): string
    {
        return DB::transaction(function () use ($p, $gatewayPaymentId, $received, $fee, $via) {
            $p = Payment::lockForUpdate()->find($p->id);
            if (! $p || $p->status !== 'awaiting') {
                return 'done';
            }
            $name = self::name($p->gateway);
            $p->fill([
                'reference' => $gatewayPaymentId,
                'gateway_fee' => $fee,
                'date' => today_iso(),
                'notes' => trim(($p->notes ? $p->notes.' · ' : '').'Paid online'.($via ? ' via '.$via : '')),
            ])->save();

            $why = null;
            if (abs($received - $p->amount) > 0.009) {
                if ($received > 0.009 && $received < $p->amount) {
                    // paid less than asked: count what actually arrived (the rest stays due), no one has to approve it
                    $p->notes = trim($p->notes.' · '.$name.' received '.money($received).' of '.money($p->amount).'; the rest stays due.');
                    $p->amount = r2($received);
                    $p->save();
                } else {
                    $why = $name.' received '.money($received).' instead of '.money($p->amount).'.';
                }
            }
            $tx = ['gateway_payment_id' => mb_substr($gatewayPaymentId, 0, 80), 'amount' => $received, 'fee' => $fee, 'channel' => $via ? mb_substr($via, 0, 40) : null];
            if (! $why) {
                $res = Rentals::confirmPayment($p, null);
                if ($res['ok']) {
                    PaymentChecks::logRental($p, 'paid', $tx + ['message' => 'Verified automatically · receipt '.$p->fresh()->receipt_no.' · order '.$p->rentalRequest->fresh()->fin()['status'].'.']);

                    return 'verified';
                }
                $why = $res['error'];
            }
            PaymentChecks::logRental($p, 'review', $tx + ['message' => mb_substr('Sent to the cashier: '.$why, 0, 255)]);
            // Money was taken but the payment can't count as-is: hand it to the cashier.
            $p->update(['status' => 'pending', 'notes' => $p->notes.' · Needs review: '.$why]);
            $r = $p->rentalRequest;
            Notifier::notify('cashier', 'cashier', 'Online payment needs review', $p->code.' for '.$r->code.' was paid through '.$name.' but could not be verified automatically: '.$why, route('cashier.verification'));
            Notifier::notify($r->customer_id, 'customer', 'Payment received', 'We received your online payment of '.money($received).' for '.$r->code.'. Our cashier will confirm it shortly.', route('rentals.show', $r));

            return 'review';
        });
    }
}
