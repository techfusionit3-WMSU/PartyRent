<?php

namespace App\Domain;

use App\Models\Discount;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Custom discounts: a shop's private discount for ONE customer (family, a regular client). Unlike the shop's public
 * promotions (App\Domain\Discounts), it is never applied automatically: the shop applies it to one of that customer's
 * rentals before any payment is made.
 *
 * - order_percent: % off the rental's items (up to 100%); order_amount: ₱ off them, never more than their total.
 * - Only the shop that made it, only on that customer's rental, only while it is on, in its dates and has uses left,
 *   only once per rental, and only before any payment (the payment amounts follow the new total).
 * - The rental keeps a copy of what was taken off (pricing.customDiscount), so editing or turning the discount off
 *   later never changes it. Removing it (still before any payment) restores the price.
 * All amounts are worked out here on the server.
 */
class CustomDiscounts
{
    public const KINDS = ['order_percent' => ['% off the rental', 'A percentage off the rental items.'], 'order_amount' => ['₱ off the rental', 'A fixed amount off the rental items.']];

    private static function fail(string $e): array
    {
        return ['ok' => false, 'error' => $e];
    }

    public static function label(Discount $d): string
    {
        return $d->kind === 'order_percent'
            ? rtrim(rtrim(number_format($d->value, 2), '0'), '.').'% off the rental'
            : money($d->value).' off the rental';
    }

    /** Customers a shop can pick from: people who rented from it (or have it in their rental list), newest first. */
    public static function knownCustomers(Supplier $s): Collection
    {
        $ids = RentalRequest::where('supplier_id', $s->id)->orderByDesc('id')->pluck('customer_id')
            ->merge(\App\Models\CartLine::where('supplier_id', $s->id)->pluck('user_id'))->unique()->values();

        return User::whereIn('id', $ids)->where('id', '!=', $s->owner_id)->get()->sortBy(fn ($u) => $ids->search($u->id))->values();
    }

    /** The registered customer for an email typed by the shop (exact match only: no browsing other people's accounts). */
    public static function findCustomer(Supplier $s, string $email): ?User
    {
        $u = User::where('email', mb_strtolower(trim($email)))->first();

        return $u && $u->id !== $s->owner_id && ! $u->isStaff() && $u->status === 'active' ? $u : null;
    }

    /** Create or edit a custom discount. $v: name, note, customer_id | customer_email, kind, value, max_uses, starts_on, ends_on. */
    public static function save(Supplier $s, array $v, ?Discount $d = null): array
    {
        $customer = ! empty($v['customer_id']) ? self::knownCustomers($s)->firstWhere('id', (int) $v['customer_id']) : null;
        $customer ??= ! empty($v['customer_email']) ? self::findCustomer($s, (string) $v['customer_email']) : null;
        if (! $customer) {
            return self::fail(! empty($v['customer_email'])
                ? 'No PartyRent customer uses that email. Check the spelling, or ask them to create an account first.'
                : 'Choose who gets this discount.');
        }
        $kind = in_array($v['kind'] ?? '', array_keys(self::KINDS), true) ? $v['kind'] : null;
        $value = round((float) ($v['value'] ?? 0), 2);
        if (! $kind) {
            return self::fail('Choose a percentage or a fixed amount.');
        }
        if ($value <= 0) {
            return self::fail('Enter a discount bigger than zero.');
        }
        if ($kind === 'order_percent' && $value > 100) {
            return self::fail('A percentage discount can be at most 100%.');
        }
        if (! empty($v['starts_on']) && ! empty($v['ends_on']) && $v['ends_on'] < $v['starts_on']) {
            return self::fail('The last day must be on or after the first day.');
        }
        $rec = ['supplier_id' => $s->id, 'customer_id' => $customer->id, 'name' => trim((string) $v['name']), 'note' => trim((string) ($v['note'] ?? '')) ?: null,
            'kind' => $kind, 'value' => $value, 'applies_to' => 'all', 'product_ids' => null, 'min_qty' => null, 'min_amount' => null,
            'max_uses' => ! empty($v['max_uses']) ? max(1, (int) $v['max_uses']) : null, 'starts_on' => $v['starts_on'] ?? null, 'ends_on' => $v['ends_on'] ?? null];
        $d ? $d->update($rec) : $d = Discount::create($rec + ['active' => true]);
        Notifier::audit($d->wasRecentlyCreated ? 'Created custom discount' : 'Updated custom discount', $d->name.' · '.self::label($d).' · for '.$customer->name.' ('.$customer->email.')');

        return ['ok' => true, 'discount' => $d];
    }

    /** Why a custom discount can't be used on a rental (null = it can). */
    public static function blocker(RentalRequest $r, Discount $d): ?string
    {
        if ($d->supplier_id !== $r->supplier_id || ! $d->isCustom()) {
            return 'This discount belongs to another shop.';
        }
        if ($d->customer_id !== $r->customer_id) {
            return 'This discount is for another customer.';
        }
        if (! $d->active) {
            return 'This discount is turned off.';
        }
        $st = $d->state();
        if ($st === 'scheduled') {
            return 'This discount starts on '.fmt_date($d->starts_on->format('Y-m-d')).'.';
        }
        if ($st === 'ended') {
            return 'This discount ended on '.fmt_date($d->ends_on->format('Y-m-d')).'.';
        }
        if ($d->max_uses && $d->usedCount() >= $d->max_uses) {
            return 'This discount has been used on '.$d->max_uses.' rental'.($d->max_uses === 1 ? '' : 's').' already.';
        }

        return null;
    }

    /** Can the rental still get (or lose) a custom discount? Only before any payment, while not closed. */
    public static function rentalBlocker(RentalRequest $r): ?string
    {
        if (! in_array($r->status, ['pending', 'accepted'], true)) {
            return 'A discount can only be added before the rental is under way.';
        }
        if (Payment::where('rental_request_id', $r->id)->whereIn('type', Finance::RENTAL_TYPES)->whereIn('status', ['verified', 'pending'])->exists()) {
            return 'The customer has already paid (or sent a payment) for this rental, so its price can\'t change now.';
        }

        return null;
    }

    /** The shop's custom discounts this rental's customer can get now. */
    public static function availableFor(RentalRequest $r): Collection
    {
        return Discount::where('supplier_id', $r->supplier_id)->where('customer_id', $r->customer_id)->where('active', true)->orderBy('id')->get()
            ->filter(fn ($d) => self::blocker($r, $d) === null)->values();
    }

    /** What the discount takes off a rental worth $base (the rental items): ['amount', 'error']. */
    public static function amountFor(Discount $d, float $base): array
    {
        if ($base <= 0) {
            return ['amount' => 0.0, 'error' => 'This rental has no items to discount.'];
        }
        $amt = $d->kind === 'order_percent' ? r2($base * min(100, $d->value) / 100) : r2($d->value);
        if ($amt > $base + 0.001) {
            return ['amount' => $amt, 'error' => money($amt).' is more than the rental items ('.money($base).'). Lower the discount first.'];
        }

        return ['amount' => min($amt, r2($base)), 'error' => null];
    }

    /** Preview of applying $d to $r: original, type / value, amount, new total (nothing saved). */
    public static function preview(RentalRequest $r, Discount $d): array
    {
        $p = $r->pricing;
        $a = self::amountFor($d, (float) ($p['rentalFee'] ?? 0));

        return ['base' => (float) ($p['rentalFee'] ?? 0), 'totalBefore' => (float) $p['total'], 'amount' => $a['amount'], 'error' => $a['error'],
            'totalAfter' => r2(max(0, (float) $p['total'] - $a['amount'])), 'label' => self::label($d)];
    }

    /** The payment stages of a stored quote follow its total (same rules as Pricing::quoteOrder). */
    private static function restage(array $p, ?array $installment): array
    {
        $t = $p['terms'] ?? [];
        $plan = $p['plan'] ?? 'full';
        $p['downPayment'] = $plan === 'full' ? 0.0 : r2($p['total'] * ($t['downPct'] ?? 0) / 100);
        $p['finalBalance'] = r2(max(0, $p['total'] - ($p['reservationFee'] ?? 0) - $p['downPayment']));
        $p['payable'] = $p['total'];

        return $p;
    }

    /** An installment proposal keeps its dates; its amounts are scaled to the new balance (last one takes the rounding). */
    private static function rescaleInstallments(?array $ip, float $old, float $new): ?array
    {
        if (! $ip || empty($ip['items']) || $old <= 0) {
            return $ip;
        }
        $items = $ip['items'];
        $sum = 0.0;
        foreach ($items as $i => $it) {
            if ($i < count($items) - 1) {
                $items[$i]['amount'] = r2($it['amount'] * $new / $old);
                $sum += $items[$i]['amount'];
            }
        }
        $items[count($items) - 1]['amount'] = r2(max(0, $new - $sum));

        return array_merge($ip, ['items' => $items]);
    }

    /** Apply a custom discount to a rental (the shop, before any payment). Everything is checked and worked out again here. */
    public static function apply(RentalRequest $r, int $discountId, User $by): array
    {
        return DB::transaction(function () use ($r, $discountId, $by) {
            $r = RentalRequest::whereKey($r->id)->lockForUpdate()->firstOrFail();
            $d = Discount::whereKey($discountId)->lockForUpdate()->first();
            if (! $d || $d->supplier_id !== $r->supplier_id || ! $d->isCustom()) {
                return self::fail('That discount is not one of your custom discounts.');
            }
            if (! empty($r->pricing['customDiscount']) || $r->custom_discount_id) {
                return self::fail('This rental already has a custom discount. Remove it first to use a different one.');
            }
            if ($e = self::rentalBlocker($r) ?? self::blocker($r, $d)) {
                return self::fail($e);
            }
            $p = $r->pricing;
            $a = self::amountFor($d, (float) ($p['rentalFee'] ?? 0));
            if ($a['error']) {
                return self::fail($a['error']);
            }
            $amt = $a['amount'];
            $before = (float) $p['total'];
            $oldBalance = (float) ($p['finalBalance'] ?? 0);
            foreach (['rentalFee', 'itemsSubtotal', 'total'] as $k) {
                if (isset($p[$k])) {
                    $p[$k] = r2(max(0, (float) $p[$k] - $amt));
                }
            }
            $p = self::restage($p, $r->installment_plan);
            $p['customDiscount'] = [
                'id' => $d->id, 'name' => $d->name, 'kind' => $d->kind, 'value' => $d->value, 'label' => self::label($d),
                'base' => (float) $r->pricing['rentalFee'], 'amount' => $amt, 'totalBefore' => $before, 'totalAfter' => $p['total'],
                'customerId' => $r->customer_id, 'appliedBy' => $by->name, 'appliedById' => $by->id, 'appliedAt' => now()->toIso8601String(),
            ];
            $r->update(['pricing' => $p, 'custom_discount_id' => $d->id] + ($r->plan === 'installment' ? ['installment_plan' => self::rescaleInstallments($r->installment_plan, $oldBalance, $p['finalBalance'])] : []));
            $r->finCache = null;
            Rentals::history($r, $r->status, 'Custom discount "'.$d->name.'" ('.self::label($d).') applied: −'.money($amt).'. Total '.money($before).' → '.money($p['total']).'.');
            Notifier::audit('Applied custom discount', $r->code.' · '.$d->name.' · −'.money($amt).' · for '.$r->customer->name, $by);
            Notifier::notify($r->customer, 'customer', 'You got a discount on '.$r->code, $r->supplier->name.' gave you '.self::label($d).' ('.$d->name.'). Your total is now '.money($p['total']).' (was '.money($before).').', route('rentals.show', $r, false));

            return ['ok' => true, 'amount' => $amt, 'total' => $p['total'], 'before' => $before];
        });
    }

    /** Take the custom discount off a rental again (before any payment): the price goes back to what it was. */
    public static function remove(RentalRequest $r, User $by): array
    {
        return DB::transaction(function () use ($r, $by) {
            $r = RentalRequest::whereKey($r->id)->lockForUpdate()->firstOrFail();
            $cd = $r->pricing['customDiscount'] ?? null;
            if (! $cd) {
                return self::fail('This rental has no custom discount.');
            }
            if ($e = self::rentalBlocker($r)) {
                return self::fail($e);
            }
            $p = $r->pricing;
            $oldBalance = (float) ($p['finalBalance'] ?? 0);
            foreach (['rentalFee', 'itemsSubtotal', 'total'] as $k) {
                if (isset($p[$k])) {
                    $p[$k] = r2((float) $p[$k] + (float) $cd['amount']);
                }
            }
            unset($p['customDiscount']);
            $p = self::restage($p, $r->installment_plan);
            $r->update(['pricing' => $p, 'custom_discount_id' => null] + ($r->plan === 'installment' ? ['installment_plan' => self::rescaleInstallments($r->installment_plan, $oldBalance, $p['finalBalance'])] : []));
            $r->finCache = null;
            Rentals::history($r, $r->status, 'Custom discount "'.$cd['name'].'" removed. Total back to '.money($p['total']).'.');
            Notifier::audit('Removed custom discount', $r->code.' · '.$cd['name'], $by);
            Notifier::notify($r->customer, 'customer', 'Discount removed from '.$r->code, $r->supplier->name.' removed the '.$cd['label'].' discount. Your total is '.money($p['total']).'.', route('rentals.show', $r, false));

            return ['ok' => true, 'total' => $p['total']];
        });
    }
}
