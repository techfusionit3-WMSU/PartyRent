<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\StockLocations;
use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;

/** Reference SP.locations: warehouses / branches, stock by location, transfers and movement records. */
class LocationController extends Controller
{
    private function own(Request $request, InventoryLocation $l): InventoryLocation
    {
        abort_unless($l->supplier_id === $request->user()->shop()->id, 404);

        return $l;
    }

    public function index(Request $request)
    {
        $s = $request->user()->shop();
        $prods = Product::where('supplier_id', $s->id)->whereNotIn('status', ['deleted', 'archived'])->orderBy('id')->get();
        inv()->primeProducts($prods);

        return view('supplier.locations', [
            's' => $s, 'locs' => $s->locations()->get(), 'prods' => $prods, 'focus' => (int) $request->query('product'),
            'moves' => StockMovement::whereIn('product_id', Product::where('supplier_id', $s->id)->pluck('id'))->with(['product', 'user', 'fromLocation', 'toLocation'])->orderByDesc('id')->limit(40)->get(),
        ]);
    }

    public function store(Request $request)
    {
        $res = StockLocations::save($request->user()->shop(), $request->only(['name', 'address', 'contact', 'phone']));

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Location saved.' : $res['error'])->withInput($res['ok'] ? [] : $request->input());
    }

    public function update(Request $request, InventoryLocation $location)
    {
        $res = StockLocations::save($request->user()->shop(), $request->only(['name', 'address', 'contact', 'phone']), $this->own($request, $location));

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Location saved.' : $res['error']);
    }

    public function destroy(Request $request, InventoryLocation $location)
    {
        $res = StockLocations::delete($request->user()->shop(), $this->own($request, $location));

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Location removed.' : $res['error']);
    }

    public function transfer(Request $request, Product $product)
    {
        abort_unless($product->supplier_id === $request->user()->shop()->id, 404);
        $v = $request->validate(['from' => 'required|integer', 'to' => 'required|integer', 'qty' => 'required|integer|min:1', 'note' => 'nullable|string|max:255']);
        $res = StockLocations::transfer($product, (int) $v['from'], (int) $v['to'], (int) $v['qty'], (string) ($v['note'] ?? ''));

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Transferred '.$v['qty'].' unit(s).' : $res['error']);
    }
}
