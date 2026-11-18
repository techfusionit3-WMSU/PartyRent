<?php

namespace App\Domain;

use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Send supplier payouts through Xendit (Money-out, /v2/payouts) instead of a manual transfer.
 *
 * Flow: a payout is prepared when the shop's payout period ends (Payouts::closeDue) → an admin clicks "Send via
 * Xendit", or it is sent automatically when the superadmin turned on payoutAuto → status "sending" →
 * Xendit transfers PartyRent's balance to the supplier's GCash / bank → the payout webhook or "Check
 * status" sees SUCCEEDED → "paid", supplier notified. A failed transfer goes back to "pending" with the
 * reason, so it can be retried or sent by hand.
 *
 * Needs a Xendit secret key with Money-out: Write.
 */
class XenditPayouts
{
    /** Banks suppliers can pick → Xendit channel codes. GCash is PH_GCASH. */
    public const BANKS = [
        'BDO' => 'PH_BDO', 'BPI' => 'PH_BPI', 'Metrobank' => 'PH_MET', 'UnionBank' => 'PH_UBP', 'Landbank' => 'PH_LBP',
        'PNB' => 'PH_PNB', 'RCBC' => 'PH_RCBC', 'Security Bank' => 'PH_SEC', 'China Bank' => 'PH_CBC', 'EastWest' => 'PH_EWB',
    ];

    public static function enabled(): bool
    {
        return Xendit::enabled();
    }

    /** Xendit channel code for the supplier's saved payout account, or null if Xendit can't send there. */
    public static function channel(Supplier $s): ?string
    {
        if (! $s->hasPayoutAccount()) {
            return null;
        }
        if ($s->payout_method === 'gcash') {
            return 'PH_GCASH';
        }
        $key = fn ($v) => strtolower(preg_replace('/[^a-z]/i', '', (string) $v));
        foreach (self::BANKS as $name => $code) {
            if ($key($name) === $key($s->payout_bank)) {
                return $code;
            }
        }

        return null;
    }

    private static function http()
    {
        return Http::withBasicAuth((string) config('services.xendit.secret_key'), '')
            ->acceptJson()->asJson()->timeout(20)->baseUrl(rtrim((string) config('services.xendit.base_url'), '/'));
    }

    /** Send a pending payout through Xendit. $by is the admin, or null when sent automatically. */
    public static function send(PayoutBatch $p, ?User $by): array
    {
        if (! self::enabled()) {
            return ['ok' => false, 'error' => 'Xendit is not set up (XENDIT_SECRET_KEY).'];
        }
        $s = $p->supplier;
        if (! $s->hasPayoutAccount()) {
            return ['ok' => false, 'error' => $s->name.' has not added a payout account yet.'];
        }
        if (! ($channel = self::channel($s))) {
            return ['ok' => false, 'error' => 'Xendit cannot send to "'.$s->payout_bank.'". Ask '.$s->name.' to pick a bank from the list, or send it by hand.'];
        }
        // claim it: only one send per payout, even with double clicks
        $claimed = DB::transaction(function () use ($p, $by) {
            $row = PayoutBatch::lockForUpdate()->find($p->id);
            if (! $row || $row->status !== 'pending') {
                return false;
            }
            $row->update(['status' => 'sending', 'gateway' => 'xendit', 'failure' => null, 'paid_by' => $by?->id]);

            return true;
        });
        if (! $claimed) {
            return ['ok' => false, 'error' => $p->code.' is already being sent or was paid.'];
        }
        $p->refresh();
        try {
            $res = self::http()->withHeaders(['Idempotency-key' => $p->code.'-'.now()->format('YmdHis')])->post('/v2/payouts', [
                'reference_id' => $p->code,
                'channel_code' => $channel,
                'channel_properties' => ['account_holder_name' => $s->payout_account_name, 'account_number' => preg_replace('/\D/', '', $s->payout_account_no)],
                'amount' => round($p->net, 2),
                'currency' => 'PHP',
                'description' => Settings::get('platformName').' payout '.$p->code.' for '.$p->periodLabel(),
                'metadata' => ['payout' => $p->code, 'period' => $p->period_start->toDateString().'/'.$p->period_end->toDateString()],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Xendit payout failed: '.$e->getMessage());
            self::fail($p, 'Could not reach Xendit.', false);

            return ['ok' => false, 'error' => 'Could not reach Xendit. Nothing was sent; try again.'];
        }
        if (! $res->successful() || ! $res->json('id')) {
            $why = (string) ($res->json('message') ?? $res->json('error_code') ?? 'HTTP '.$res->status());
            Log::warning('Xendit payout refused: '.$res->body());
            self::fail($p, $why, false);

            return ['ok' => false, 'error' => 'Xendit refused the payout: '.$why];
        }
        $p->update(['gateway_ref' => $res->json('id'), 'method' => $s->payout_method, 'reference' => $res->json('id')]);
        Notifier::audit('Sent payout via Xendit', $p->code.' · '.$s->name.' · '.money($p->net).' · '.$res->json('id'), $by);

        return ['ok' => true, 'state' => self::apply($p, (array) $res->json())];
    }

    /** Ask Xendit how a "sending" payout is doing. Returns paid | sending | failed | done | error. */
    public static function sync(PayoutBatch $p): string
    {
        if ($p->status !== 'sending' || ! $p->gateway_ref) {
            return 'done';
        }
        try {
            $res = self::http()->get('/v2/payouts/'.$p->gateway_ref);
        } catch (\Throwable $e) {
            return 'error';
        }

        return $res->successful() ? self::apply($p, (array) $res->json()) : 'error';
    }

    /** Apply a Xendit payout object (from the API or the webhook). */
    public static function apply(PayoutBatch $p, array $x): string
    {
        $status = strtoupper((string) ($x['status'] ?? ''));
        if ($status === 'SUCCEEDED') {
            return self::finish($p);
        }
        if (in_array($status, ['FAILED', 'CANCELLED', 'REVERSED', 'EXPIRED'], true)) {
            self::fail($p, ucfirst(strtolower($status)).($x['failure_code'] ?? null ? ': '.str_replace('_', ' ', strtolower($x['failure_code'])) : ''), true);

            return 'failed';
        }

        return 'sending';
    }

    private static function finish(PayoutBatch $p): string
    {
        return DB::transaction(function () use ($p) {
            $p = PayoutBatch::lockForUpdate()->find($p->id);
            if (! $p || $p->status !== 'sending') {
                return 'done';
            }
            $p->update(['status' => 'paid', 'paid_at' => now(), 'failure' => null]);
            Payouts::markLinesPaid($p);
            Notifier::notify($p->supplier->owner_id, 'supplier', 'Payout sent 💸', money($p->net).' for '.$p->periodLabel().' was sent to your '.$p->methodLabel().' account through Xendit. Reference '.$p->reference.' ('.$p->code.').', route('supplier.payouts', [], false));
            Notifier::audit('Payout received by supplier', $p->code.' · '.money($p->net).' · Xendit '.$p->reference);

            return 'paid';
        });
    }

    private static function fail(PayoutBatch $p, string $why, bool $tellAdmin): void
    {
        $p->update(['status' => 'pending', 'gateway' => null, 'gateway_ref' => null, 'reference' => null, 'method' => null, 'paid_by' => null, 'failure' => mb_substr($why, 0, 250)]);
        if ($tellAdmin) {
            Notifier::notify('admin', 'admin', 'Payout failed', $p->code.' to '.$p->supplier->name.' ('.money($p->net).') failed in Xendit: '.$why.'. Check the account and send it again.', route('admin.payouts', [], false));
        }
    }

    /** Payout webhook from Xendit (event payout.succeeded / payout.failed …), already token-checked. */
    public static function handleCallback(array $body): string
    {
        $x = (array) ($body['data'] ?? $body);
        $p = empty($x['id']) ? null : PayoutBatch::where('gateway', 'xendit')->where('gateway_ref', $x['id'])->first();

        return $p ? self::apply($p, $x) : 'unknown';
    }

    /** Called after a payout is prepared: send it right away when automatic payouts are on. */
    public static function autoSend(PayoutBatch $p): void
    {
        if (Settings::get('payoutAuto', false) && self::enabled() && self::channel($p->supplier)) {
            self::send($p, null);
        }
    }
}
