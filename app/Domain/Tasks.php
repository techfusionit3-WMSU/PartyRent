<?php

namespace App\Domain;

use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\ShortfallCase;
use App\Models\Supplier;
use App\Models\User;

/**
 * "Needs you": the few things a company role has to do now, each with one button.
 * Item: [icon, title, sub, actions[]] where an action is
 *   ['label' => …, 'url' => …]                                      (link)
 *   ['label' => …, 'post' => url, 'fields' => [...], 'confirm' => …] (one-click form with a confirm dialog)
 * Buttons only appear when the user's company permissions allow that action.
 */
class Tasks
{
    private const MAX = 6;

    public static function forModule(User $u, string $module): array
    {
        $s = $u->shop();
        if (! $s) {
            return [];
        }
        $items = match ($module) {
            'manage' => array_merge(self::requests($u, $s), self::payments($u, $s, 'supplier'), self::plans($s), self::returns($s), self::prepare($u, $s)),
            'desk' => array_merge(self::requests($u, $s), self::messages($s), self::plans($s), self::cases($s)),
            'cashier' => array_merge(self::payments($u, $s, 'cashier'), self::overdue($s)),
            'inventory' => array_merge(self::prepare($u, $s), self::returns($s), self::lowStock($s)),
            default => [],
        };

        return array_slice($items, 0, self::MAX);
    }

    private static function requests(User $u, Supplier $s): array
    {
        $out = [];
        foreach (RentalRequest::where('supplier_id', $s->id)->where('status', 'pending')->with('customer')->orderBy('created_at')->limit(4)->get() as $r) {
            $bad = inv()->check($r->lines, $r->id)['bad'];
            $acts = [];
            if (! $bad && $u->orgCan('orders.manage')) {
                $acts[] = ['label' => __('Accept'), 'post' => route('supplier.requests.accept', $r), 'confirm' => __('Accept :code from :name? The customer is asked to pay the first payment.', ['code' => $r->code, 'name' => $r->customer->name])];
            }
            $acts[] = ['label' => $bad ? __('Check stock') : __('Review'), 'url' => route('supplier.requests.show', $r)];
            $out[] = ['list', __('New request :code', ['code' => $r->code]), $r->customer->name.' · '.fmt_range($r->start_date, $r->end_date).($bad ? ' · '.__('not enough stock') : ''), $acts];
        }

        return $out;
    }

    /** Cash payments the shop has to confirm (online payments are confirmed by the gateway / PartyRent). */
    private static function payments(User $u, Supplier $s, string $where): array
    {
        if (! $u->orgCan('payments.verify')) {
            return [];
        }
        $out = [];
        $pays = Payment::where('status', 'pending')->whereIn('method', ['cash', 'cod'])
            ->whereHas('rentalRequest', fn ($q) => $q->where('supplier_id', $s->id))->with('rentalRequest.customer')->orderBy('submitted_at')->limit(4)->get();
        foreach ($pays as $p) {
            $r = $p->rentalRequest;
            $route = $where === 'cashier' ? route('cashier.verify', $p) : route('supplier.payments.verify', $p);
            $out[] = ['card', __('Confirm :name\'s :amount payment', ['name' => $r->customer->firstName(), 'amount' => money((float) $p->amount)]), $r->code.' · '.(Finance::METHOD_LABEL[$p->method] ?? $p->method),
                [['label' => __('Confirm payment'), 'post' => $route, 'confirm' => __('Confirm that you received :amount from :name? A receipt is issued.', ['amount' => money((float) $p->amount), 'name' => $r->customer->name])],
                 ['label' => __('Open'), 'url' => $where === 'cashier' ? route('cashier.verification') : route('supplier.requests.show', $r)]]];
        }

        return $out;
    }

    private static function plans(Supplier $s): array
    {
        return RentalRequest::where('supplier_id', $s->id)->whereIn('status', Finance::ACTIVE)->with('customer')->get()
            ->filter(fn ($r) => ($r->installment_plan['status'] ?? null) === 'proposed')->take(3)
            ->map(fn ($r) => ['clock', __('Installment plan to review'), $r->code.' · '.$r->customer->name, [['label' => __('Review'), 'url' => route('supplier.requests.show', $r)]]])->values()->all();
    }

    private static function messages(Supplier $s): array
    {
        $out = [];
        $reqs = RentalRequest::where('supplier_id', $s->id)->whereNotIn('status', ['declined', 'cancelled', 'completed'])->with('customer')->get();
        $last = \App\Models\RequestMessage::whereIn('rental_request_id', $reqs->pluck('id'))->where('side', 'customer')
            ->selectRaw('rental_request_id, max(created_at) as last_at')->groupBy('rental_request_id')->pluck('last_at', 'rental_request_id');
        foreach ($reqs as $r) {
            if (isset($last[$r->id]) && (! $r->chat_supplier_seen_at || $r->chat_supplier_seen_at->lt($last[$r->id]))) {
                $out[] = ['message', __(':name sent a message', ['name' => $r->customer->firstName()]), $r->code, [['label' => __('Reply'), 'url' => route('supplier.requests.show', $r).'#chat']]];
            }
        }

        return array_slice($out, 0, 3);
    }

    private static function cases(Supplier $s): array
    {
        return ShortfallCase::where('supplier_id', $s->id)->where('status', '!=', 'resolved')->latest()->limit(3)->get()
            ->map(fn ($c) => ['alert', __('Open case :code', ['code' => $c->code]), ucfirst(str_replace('_', ' ', $c->status)), [['label' => __('Open'), 'url' => route('cases.show', $c)]]])->all();
    }

    private static function overdue(Supplier $s): array
    {
        return RentalRequest::where('supplier_id', $s->id)->whereIn('status', Finance::ACTIVE)->with(['customer', 'payments'])->get()
            ->filter(fn ($r) => $r->fin()['isOverdue'])->take(3)
            ->map(fn ($r) => ['alert', __(':name is late: :amount', ['name' => $r->customer->firstName(), 'amount' => money($r->fin()['overdueAmount'])]), $r->code, [['label' => __('View balances'), 'url' => route('cashier.balances')]]])->values()->all();
    }

    /** Reserved rentals starting within 3 days that nobody started preparing. */
    private static function prepare(User $u, Supplier $s): array
    {
        $out = [];
        foreach (RentalRequest::where('supplier_id', $s->id)->where('status', 'accepted')->whereNotNull('reserved_at')
            ->where('start_date', '<=', add_days(today_iso(), 3))->with('customer')->orderBy('start_date')->limit(3)->get() as $r) {
            $acts = [];
            if ($u->orgCan('fulfillment.update') && ! Rentals::stepBlockers($r, 'in_progress')) {
                $acts[] = ['label' => __('Start preparing'), 'post' => route('supplier.requests.advance', $r), 'fields' => ['to' => 'in_progress'], 'confirm' => __('Start preparing the items for :code? The customer is notified.', ['code' => $r->code])];
            }
            $acts[] = ['label' => __('Open'), 'url' => route('supplier.requests.show', $r)];
            $out[] = ['box', __('Prepare :code', ['code' => $r->code]), __('goes out :date', ['date' => fmt_day($r->start_date)]).' · '.$r->customer->name, $acts];
        }

        return $out;
    }

    private static function returns(Supplier $s): array
    {
        return RentalRequest::where('supplier_id', $s->id)->where('status', 'return_pending')->with('customer')->orderBy('end_date')->limit(3)->get()
            ->map(fn ($r) => ['check', __('Inspect the return of :code', ['code' => $r->code]), $r->customer->name, [['label' => __('Inspect'), 'url' => route('supplier.requests.show', $r)]]])->all();
    }

    private static function lowStock(Supplier $s): array
    {
        return Product::where('supplier_id', $s->id)->where('listing_type', 'sale')->whereNotIn('status', ['deleted', 'archived'])->get()
            ->filter->isLowStock()->take(3)
            ->map(fn ($p) => ['layers', __('Restock :name', ['name' => $p->name]), __(':n left', ['n' => $p->stockOnHand()]), [['label' => __('Open inventory'), 'url' => route('supplier.inventory')]]])->values()->all();
    }
}
