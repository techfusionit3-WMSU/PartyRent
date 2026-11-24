<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Notifier;
use App\Domain\Pricing;
use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Catalog → Discounts (change list C11): every discount that can lower a product's price in one place —
 * shop discounts (percent / ₱ per piece, all or selected items, minimum quantity, period, on/off) and each
 * item's bulk discount (base price + quantity steps, the same tiers the product form edits).
 * Requests already placed keep the price they were quoted with (see App\Domain\Discounts).
 */
class DiscountController extends Controller
{
    private function sup(Request $r)
    {
        return $r->user()->shop();
    }

    /** One of this shop's public promotions (custom discounts have their own actions and permission). */
    private function own(Request $r, Discount $d): Discount
    {
        abort_unless($d->supplier_id === $this->sup($r)->id && ! $d->isCustom(), 404);

        return $d;
    }

    /** One of this shop's custom (customer) discounts. */
    private function ownCustom(Request $r, Discount $d): Discount
    {
        abort_unless($d->supplier_id === $this->sup($r)->id && $d->isCustom(), 404);

        return $d;
    }

    public function index(Request $request)
    {
        $s = $this->sup($request);
        $products = Product::where('supplier_id', $s->id)->whereNotIn('status', ['deleted', 'archived'])->orderBy('name')->get();

        return view('supplier.discounts', [
            'list' => Discount::where('supplier_id', $s->id)->whereNull('customer_id')->orderByDesc('active')->orderByDesc('id')->get(),
            'custom' => Discount::where('supplier_id', $s->id)->whereNotNull('customer_id')->with('customer')->withCount(['rentals as used_count' => fn ($q) => $q->whereNotIn('status', ['declined', 'cancelled'])])
                ->orderByDesc('active')->orderByDesc('id')->get(),
            'customers' => \App\Domain\CustomDiscounts::knownCustomers($s),
            'canCustom' => $request->user()->orgCan('shop.manage'),
            'products' => $products,
        ]);
    }

    private function data(Request $request): array
    {
        $s = $this->sup($request);
        $v = $request->validate([
            'name' => 'required|string|min:2|max:80',
            'kind' => 'required|in:percent,amount,order_percent,order_amount',
            'min_amount' => 'nullable|numeric|min:1|max:10000000',
            'value' => 'required|numeric|min:0.01|max:1000000',
            'applies_to' => 'required|in:all,products',
            'product_ids' => 'nullable|array', 'product_ids.*' => 'integer',
            'min_qty' => 'nullable|integer|min:2|max:100000',
            'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date',
        ], ['value.required' => 'Enter how much the discount takes off.']);
        if (in_array($v['kind'], ['percent', 'order_percent'], true) && $v['value'] > 90) {
            throw ValidationException::withMessages(['value' => 'A percent discount can be at most 90%.']);
        }
        if (! empty($v['starts_on']) && ! empty($v['ends_on']) && $v['ends_on'] < $v['starts_on']) {
            throw ValidationException::withMessages(['ends_on' => 'The end date must be on or after the start date.']);
        }
        $ids = [];
        if ($v['applies_to'] === 'products') {
            $ids = Product::where('supplier_id', $s->id)->whereIn('id', $v['product_ids'] ?? [])->pluck('id')->all();
            if (! $ids) {
                throw ValidationException::withMessages(['product_ids' => 'Pick at least one item, or apply the discount to all items.']);
            }
        }

        return [
            'name' => trim($v['name']), 'kind' => $v['kind'], 'value' => round((float) $v['value'], 2), 'applies_to' => $v['applies_to'],
            'product_ids' => $v['applies_to'] === 'products' ? $ids : null, 'min_qty' => $v['min_qty'] ?? null,
            'min_amount' => str_starts_with($v['kind'], 'order_') ? ($v['min_amount'] ?? null) : null,
            'starts_on' => $v['starts_on'] ?? null, 'ends_on' => $v['ends_on'] ?? null,
        ];
    }

    public function store(Request $request)
    {
        $d = Discount::create($this->data($request) + ['supplier_id' => $this->sup($request)->id, 'active' => true]);
        Notifier::audit('Created discount', $d->name.' · '.$d->label());

        return redirect()->route('supplier.discounts')->with('success', $d->name.' saved. It applies to new requests from now on.');
    }

    public function update(Request $request, Discount $discount)
    {
        $d = $this->own($request, $discount);
        $d->update($this->data($request));
        Notifier::audit('Updated discount', $d->name.' · '.$d->label());

        return redirect()->route('supplier.discounts')->with('success', $d->name.' updated. Requests already placed keep their price.');
    }

    public function active(Request $request, Discount $discount)
    {
        $d = $this->own($request, $discount);
        $d->update(['active' => ! $d->active]);
        Notifier::audit($d->active ? 'Turned on discount' : 'Turned off discount', $d->name);

        return back()->with('success', $d->name.($d->active ? ' is on.' : ' is off. Requests already placed keep their price.'));
    }

    /* ---------- custom discounts for one customer (family, regulars) ---------- */

    private function customData(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|min:2|max:80', 'note' => 'nullable|string|max:160',
            'customer_id' => 'nullable|integer', 'customer_email' => 'nullable|email|max:180',
            'kind' => 'required|in:order_percent,order_amount', 'value' => 'required|numeric|min:0.01|max:1000000',
            'max_uses' => 'nullable|integer|min:1|max:1000', 'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date',
        ], ['name.required' => 'Give the discount a name, e.g. "Family discount".', 'value.required' => 'Enter how much the discount takes off.',
            'value.min' => 'Enter a discount bigger than zero.', 'customer_email.email' => 'Enter a valid email address.']);
    }

    public function customStore(Request $request)
    {
        $res = \App\Domain\CustomDiscounts::save($this->sup($request), $this->customData($request));
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return redirect()->route('supplier.discounts', ['tab' => 'custom'])->with('success', $res['discount']->name.' saved for '.$res['discount']->customer->name.'. Apply it from one of their rentals (open the rental, then "Customer discount").');
    }

    public function customUpdate(Request $request, Discount $discount)
    {
        $d = $this->ownCustom($request, $discount);
        $res = \App\Domain\CustomDiscounts::save($this->sup($request), $this->customData($request), $d);

        return redirect()->route('supplier.discounts', ['tab' => 'custom'])->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $d->name.' updated. Rentals it was already used on keep their price.' : $res['error']);
    }

    public function customActive(Request $request, Discount $discount)
    {
        $d = $this->ownCustom($request, $discount);
        $d->update(['active' => ! $d->active]);
        Notifier::audit($d->active ? 'Turned on custom discount' : 'Turned off custom discount', $d->name.' · for '.$d->customer?->name);

        return back()->with('success', $d->name.($d->active ? ' can be used again.' : ' is turned off. Rentals it was already used on keep their price.'));
    }

    public function customDestroy(Request $request, Discount $discount)
    {
        $d = $this->ownCustom($request, $discount);
        if ($d->rentals()->exists()) {
            return back()->with('error', $d->name.' was used on a rental, so it stays on record. Turn it off instead.');
        }
        $name = $d->name;
        $d->delete();
        Notifier::audit('Deleted custom discount', $name);

        return back()->with('success', $name.' deleted.');
    }

    public function destroy(Request $request, Discount $discount)
    {
        $d = $this->own($request, $discount);
        $name = $d->name;
        $d->delete();
        Notifier::audit('Deleted discount', $name);

        return back()->with('success', $name.' deleted. Requests already placed keep their price.');
    }

    /** Bulk discount of one item: base price + quantity steps (same tiers as the product form). */
    public function bulk(Request $request, Product $product)
    {
        abort_unless($product->supplier_id === $this->sup($request)->id, 404);
        $v = $request->validate([
            'base_price' => 'required|numeric|min:0.01|max:10000000',
            'bulk' => 'nullable|array|max:20', 'bulk.*.min' => 'nullable|integer|min:2|max:100000', 'bulk.*.pct' => 'nullable|numeric', 'bulk.*.price' => 'nullable|numeric|min:0.01',
        ], ['bulk.*.min.min' => 'A bulk discount starts at 2 or more pieces.']);
        $tiers = Pricing::tiersFrom((float) $v['base_price'], $v['bulk'] ?? [], $product->isSale() ? null : (int) $product->total_stock);
        if (is_string($tiers)) {
            return back()->withInput()->with('error', $product->name.': '.$tiers);
        }
        $product->update(['tiers' => $tiers]);
        inv()->flush();
        Notifier::audit('Updated product pricing', $product->name.' · '.money($tiers[0]['price']).($tiers[1] ?? null ? ' · bulk: '.implode(', ', array_map(fn ($t) => $t['min'].'+ '.Pricing::pctText($t['pct']).'% off ('.money($t['price']).')', array_slice($tiers, 1))) : ''));

        return back()->with('success', $product->name.': '.(Pricing::bulkLine($product->fresh()) ?: 'no bulk discount').'.');
    }
}
