<?php

namespace App\Domain;

use App\Models\PayoutAccount;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A shop's payout accounts: add (with the fields each provider needs), make one the default (where payouts go),
 * remove. Numbers are checked per provider, saved encrypted and shown masked. Debit cards are only added through
 * a card-payout provider's secure form (tokenization); PartyRent never receives the card number or CVV.
 */
class PayoutAccounts
{
    /** Is a card-payout provider connected? (services.payout_cards.provider in config, not yet available) */
    public static function cardsEnabled(): bool
    {
        return (bool) config('services.payout_cards.provider');
    }

    /** Validate the form for one provider and return the clean values. */
    public static function validate(array $in): array
    {
        $cat = (string) ($in['category'] ?? '');
        $prov = (string) ($in['provider'] ?? '');
        $err = fn ($f, $m) => throw ValidationException::withMessages([$f => $m]);
        if (! isset(PayoutAccount::PROVIDERS[$cat])) {
            $err('category', 'Choose e-wallet, bank account or debit card.');
        }
        if ($cat === 'card') {
            // never accept card numbers here: only the provider's token, from its own hosted form
            $err('category', self::cardsEnabled() ? 'Add a debit card from the secure card form.' : 'Debit card payouts are not available yet: PartyRent has not connected a card-payout provider. Use an e-wallet or bank account.');
        }
        if (! isset(PayoutAccount::PROVIDERS[$cat][$prov])) {
            $err('provider', 'Choose the '.($cat === 'bank' ? 'bank' : 'e-wallet').'.');
        }
        $name = trim(preg_replace('/\s+/', ' ', (string) ($in['account_name'] ?? '')));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120 || ! preg_match("/^[\pL\pM .,'&()\-]+$/u", $name)) {
            $err('account_name', 'Enter the account name exactly as the '.($cat === 'bank' ? 'bank' : 'e-wallet').' has it (letters only).');
        }
        $digits = preg_replace('/[\s\-]/', '', (string) ($in['account_number'] ?? ''));
        [$rule, $hint] = PayoutAccount::NUMBER_RULES[$prov];
        if (! preg_match($rule, $digits)) {
            $err('account_number', 'Enter '.$hint.'.');
        }
        $label = PayoutAccount::PROVIDERS[$cat][$prov];
        $details = [];
        if (in_array($prov, ['other_ewallet', 'other_bank'], true)) {
            $label = trim((string) ($in['provider_name'] ?? ''));
            if (mb_strlen($label) < 2 || mb_strlen($label) > 60) {
                $err('provider_name', 'Enter the '.($cat === 'bank' ? 'bank' : 'e-wallet').' name.');
            }
        }
        if ($cat === 'bank') {
            $type = (string) ($in['account_type'] ?? '');
            if (! in_array($type, ['savings', 'checking'], true)) {
                $err('account_type', 'Choose savings or checking.');
            }
            $details = ['accountType' => $type, 'branch' => mb_substr(trim((string) ($in['branch'] ?? '')), 0, 80)];
        }

        return ['category' => $cat, 'provider' => $prov, 'provider_name' => $label, 'account_name' => $name, 'account_number' => $digits, 'last4' => substr($digits, -4), 'details' => $details];
    }

    public static function add(Supplier $s, array $in, User $by): PayoutAccount
    {
        $data = self::validate($in);
        $existing = $s->ensurePayoutAccounts();
        if ($existing->contains(fn ($a) => $a->provider === $data['provider'] && $a->provider_name === $data['provider_name'] && (string) $a->account_number === $data['account_number'])) {
            throw ValidationException::withMessages(['account_number' => 'This account is already saved.']);
        }
        if ($existing->count() >= 10) {
            throw ValidationException::withMessages(['account_number' => 'A shop can keep up to 10 payout accounts. Remove one first.']);
        }

        return DB::transaction(function () use ($s, $data, $by, $existing, $in) {
            $a = $s->payoutAccounts()->create($data + ['created_by' => $by->id, 'is_default' => false]);
            if ($existing->isEmpty() || ! empty($in['make_default'])) {
                self::makeDefault($s, $a, $by, false);
            }
            Notifier::audit('Added payout account', $s->name.' · '.$a->label(), $by);

            return $a->fresh();
        });
    }

    public static function makeDefault(Supplier $s, PayoutAccount $a, User $by, bool $audit = true): void
    {
        abort_unless($a->supplier_id === $s->id, 404);
        DB::transaction(function () use ($s, $a) {
            $s->payoutAccounts()->whereKeyNot($a->id)->update(['is_default' => false]);
            $a->update(['is_default' => true]);
            $a->setRelation('supplier', $s)->mirror();
        });
        if ($audit) {
            Notifier::audit('Changed default payout account', $s->name.' · '.$a->label(), $by);
        }
    }

    /** Remove an account; the default can only go when another one takes its place (or it is the last one). */
    public static function remove(Supplier $s, PayoutAccount $a, User $by): array
    {
        abort_unless($a->supplier_id === $s->id, 404);
        $others = $s->payoutAccounts()->whereKeyNot($a->id)->get();
        if ($a->is_default && $others->isNotEmpty()) {
            return ['ok' => false, 'error' => 'This is where your payouts go. Make another account the default first.'];
        }
        if ($a->is_default && Payouts::summary($s->id)['pending'] > 0) {
            return ['ok' => false, 'error' => 'A payout is being prepared for this account. Add another account and make it the default first.'];
        }
        $label = $a->label();
        $a->delete();
        if ($others->isEmpty()) {
            $s->forceFill(['payout_method' => null, 'payout_bank' => null, 'payout_account_name' => null, 'payout_account_no' => null])->save();
        }
        Notifier::audit('Removed payout account', $s->name.' · '.$label, $by);

        return ['ok' => true];
    }
}
