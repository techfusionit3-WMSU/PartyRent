<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Branches;
use App\Domain\Inventory;
use App\Domain\StockLocations;
use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\StockMovement;
use Illuminate\Http\Request;

/**
 * Branch management (reference SP.locations): the supplier's branches with their own address, pin and stock;
 * stock per branch, transfers, movement records, and the rentals each branch fulfils.
 */
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
        Branches::main($s);
        $prods = Product::where('supplier_id', $s->id)->whereNotIn('status', ['deleted', 'archived'])->orderBy('id')->get();
        inv()->primeProducts($prods);
        $open = RentalRequest::where('supplier_id', $s->id)->whereIn('status', array_merge(['pending'], Inventory::RESERVING))->get(['id', 'branch_id', 'status']);

        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) ? $request->query('date') : today_iso();

        return view('supplier.locations', [
            's' => $s, 'locs' => $s->branches()->get(), 'prods' => $prods, 'focus' => (int) $request->query('product'), 'date' => $date,
            'pkgs' => \App\Models\Package::where('supplier_id', $s->id)->where('active', true)->where('is_draft', false)->orderBy('id')->get(),
            'open' => $open->groupBy('branch_id')->map(fn ($g) => ['pending' => $g->where('status', 'pending')->count(), 'booked' => $g->where('status', '!=', 'pending')->count()]),
            'moves' => StockMovement::whereIn('product_id', Product::where('supplier_id', $s->id)->pluck('id'))->with(['product', 'user', 'fromLocation', 'toLocation'])->orderByDesc('id')->limit(40)->get(),
        ]);
    }

    /** Add / edit a branch on its own page (address picker and map pin). */
    public function create(Request $request)
    {
        return view('supplier.branch-form', ['s' => $request->user()->shop(), 'l' => new InventoryLocation(['active' => true])]);
    }

    public function edit(Request $request, InventoryLocation $location)
    {
        return view('supplier.branch-form', ['s' => $request->user()->shop(), 'l' => $this->own($request, $location)]);
    }

    private function input(Request $request): array
    {
        $request->validate([
            'name' => 'required|string|min:2|max:120', 'address' => 'nullable|string|max:300', 'addr' => 'nullable|array',
            'contact' => 'nullable|string|max:120', 'phone' => 'nullable|string|max:40',
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180|required_with:lat', 'is_main' => 'nullable|boolean',
        ]);

        return $request->only(['name', 'address', 'addr', 'contact', 'phone', 'is_main']) + ($request->has('lat') ? ['lat' => $request->input('lat'), 'lng' => $request->input('lng')] : []);
    }

    public function store(Request $request)
    {
        $res = StockLocations::save($request->user()->shop(), $this->input($request));

        return $res['ok']
            ? redirect()->route('supplier.locations')->with('success', $res['branch']->name.' added. Set its stock per item on the product pages (or transfer stock below).')
            : back()->with('error', $res['error'])->withInput();
    }

    public function update(Request $request, InventoryLocation $location)
    {
        $res = StockLocations::save($request->user()->shop(), $this->input($request), $this->own($request, $location));

        return $res['ok'] ? redirect()->route('supplier.locations')->with('success', $location->fresh()->name.' saved.') : back()->with('error', $res['error'])->withInput();
    }

    /** Deactivate / reactivate a branch. */
    public function active(Request $request, InventoryLocation $location)
    {
        $l = $this->own($request, $location);
        $res = StockLocations::setActive($request->user()->shop(), $l, ! $l->active);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $l->name.($l->fresh()->active ? ' takes rentals again.' : ' is deactivated: customers can no longer rent from it.') : $res['error']);
    }

    public function destroy(Request $request, InventoryLocation $location)
    {
        $res = StockLocations::delete($request->user()->shop(), $this->own($request, $location));

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Branch removed.' : $res['error']);
    }

    public function transfer(Request $request, Product $product)
    {
        abort_unless($product->supplier_id === $request->user()->shop()->id, 404);
        $v = $request->validate(['from' => 'required|integer', 'to' => 'required|integer', 'qty' => 'required|integer|min:1', 'note' => 'nullable|string|max:255']);
        $res = StockLocations::transfer($product, (int) $v['from'], (int) $v['to'], (int) $v['qty'], (string) ($v['note'] ?? ''));

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Transferred '.$v['qty'].' unit(s).' : $res['error']);
    }
}
