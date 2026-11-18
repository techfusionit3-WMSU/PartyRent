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

    private function own(Request $r, Discount $d): Discount
    {
        abort_unless($d->supplier_id === $this->sup($r)->id, 404);

        return $d;
    }

    public function index(Request $request)
    {
        $s = $this->sup($request);
        $products = Product::where('supplier_id', $s->id)->whereNotIn('status', ['deleted', 'archived'])->orderBy('name')->get();

        return view('supplier.discounts', [
            'list' => Discount::where('supplier_id', $s->id)->orderByDesc('active')->orderByDesc('id')->get(),
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
            'bulk' => 'nullable|array|max:7', 'bulk.*.min' => 'nullable|integer|min:2', 'bulk.*.price' => 'nullable|numeric|min:0.01',
        ], ['bulk.*.min.min' => 'A bulk discount starts at 2 or more pieces.']);
        $tiers = Pricing::tiersFrom((float) $v['base_price'], $v['bulk'] ?? []);
        if (is_string($tiers)) {
            return back()->withInput()->with('error', $product->name.': '.$tiers);
        }
        if (collect($tiers)->skip(1)->contains(fn ($t) => $t['price'] >= $tiers[0]['price'])) {
            return back()->withInput()->with('error', $product->name.': each bulk price must be lower than the base price.');
        }
        $product->update(['tiers' => $tiers]);
        inv()->flush();
        Notifier::audit('Updated product pricing', $product->name.' · '.implode(', ', array_map(fn ($t) => $t['min'].($t['max'] ? '–'.$t['max'] : '+').': '.money($t['price']), $tiers)));

        return back()->with('success', $product->name.': '.(Pricing::bulkLine($product->fresh()) ?: 'no bulk discount').'.');
    }
}
