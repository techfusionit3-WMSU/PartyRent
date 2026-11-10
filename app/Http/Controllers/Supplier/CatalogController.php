<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Ai;
use App\Domain\Notifier;
use App\Domain\Packages;
use App\Domain\Rentals;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Hold;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    private function sup(Request $r)
    {
        return $r->user()->supplier;
    }

    private function ownProduct(Request $r, Product $p): Product
    {
        abort_unless($p->supplier_id === $this->sup($r)->id, 404);

        return $p;
    }

    /* ---------- products ---------- */

    public function products(Request $request)
    {
        $list = Product::where('supplier_id', $this->sup($request)->id)->where('status', '!=', 'archived')->with('category')->orderBy('name')->get();
        inv()->primeProducts($list);

        return view('supplier.products', ['list' => $list, 'today' => today_iso()]);
    }

    public function createProduct(Request $request)
    {
        return view('supplier.product-form', ['p' => new Product(['listing_type' => $request->query('type') === 'sale' ? 'sale' : 'rent', 'tiers' => [['min' => 1, 'max' => null, 'price' => '']], 'unit' => $request->query('type') === 'sale' ? 'pc' : 'piece / day', 'condition' => 'Excellent', 'status' => 'published', 'delivery' => true, 'pickup' => true, 'colors' => [], 'themes' => [], 'events' => []]), 'cats' => Category::where('active', true)->orderBy('sort')->get(), 'events' => array_keys(Ai::EVENTS)]);
    }

    public function editProduct(Request $request, Product $product)
    {
        return view('supplier.product-form', ['p' => $this->ownProduct($request, $product), 'cats' => Category::where('active', true)->orderBy('sort')->get(), 'events' => array_keys(Ai::EVENTS)]);
    }

    private function productData(Request $request): array
    {
        $v = $request->validate([
            'name' => 'required|string|min:3|max:120',
            'category_id' => 'required|exists:categories,id',
            'description' => 'required|string|min:10|max:2000',
            'listing_type' => 'nullable|in:rent,sale',
            'total_stock' => 'required|integer|min:0|max:100000',
            'unit' => 'required|string|max:40',
            'perishable' => 'nullable|boolean',
            'lead_days' => 'nullable|integer|min:0|max:60',
            'shelf_life_days' => 'nullable|integer|min:1|max:3650',
            'low_stock_at' => 'nullable|integer|min:0|max:100000',
            'condition' => 'required|string|max:40',
            'status' => 'required|in:published,draft,hidden',
            'tiers' => 'required|array|min:1|max:8',
            'tiers.*.min' => 'nullable|integer|min:1',
            'tiers.*.max' => 'nullable|integer|min:1',
            'tiers.*.price' => 'nullable|numeric|min:0',
            'colors' => 'nullable|string|max:255',
            'themes' => 'nullable|string|max:255',
            'events' => 'nullable|array', 'events.*' => 'string|max:40',
            'delivery' => 'nullable|boolean',
            'handling_fee' => 'nullable|numeric|min:0|max:1000000',
            'handling_per' => 'nullable|in:line,unit',
            'images' => 'nullable|array|max:5', 'images.*' => 'image|max:8192',
            'listing_type' => 'nullable|in:rent,sale',
            'perishable' => 'nullable|boolean',
            'lead_days' => 'nullable|integer|min:0|max:60',
            'shelf_life_days' => 'nullable|integer|min:1|max:3650',
            'low_stock_at' => 'nullable|integer|min:0|max:100000',
        ]);
        $sale = ($v['listing_type'] ?? 'rent') === 'sale';
        if ($sale && ! in_array($v['unit'], Product::SALE_UNITS, true)) {
            $v['unit'] = 'pc';
        }
        if (! $sale && ! str_contains($v['unit'], '/')) {
            $v['unit'] = 'piece / day';
        }
        $split = fn ($s) => array_values(array_filter(array_map(fn ($x) => ucwords(trim($x)), explode(',', (string) $s))));
        $sale = ($v['listing_type'] ?? 'rent') === 'sale';
        $unit = $sale
            ? (in_array($v['unit'], Product::SALE_UNITS, true) ? $v['unit'] : 'pc')
            : (str_contains($v['unit'], '/') ? $v['unit'] : 'piece / day');

        return [
            'listing_type' => $sale ? 'sale' : 'rent',
            'perishable' => $sale && $request->boolean('perishable'),
            'lead_days' => $sale ? (int) ($v['lead_days'] ?? 0) : 0,
            'shelf_life_days' => $sale && $request->boolean('perishable') ? ($v['shelf_life_days'] ?? null) : null,
            'low_stock_at' => $sale ? ($v['low_stock_at'] ?? null) : null,
            'name' => trim($v['name']), 'category_id' => $v['category_id'], 'description' => trim($v['description']),
            'total_stock' => (int) $v['total_stock'], 'unit' => $unit, 'condition' => $v['condition'], 'status' => $v['status'],
            'tiers' => $v['tiers'], 'colors' => $split($v['colors'] ?? ''), 'themes' => $split($v['themes'] ?? ''), 'events' => $v['events'] ?? [],
            'delivery' => $request->boolean('delivery'), 'pickup' => true,
            'handling_fee' => r2($v['handling_fee'] ?? 0), 'handling_per' => $v['handling_per'] ?? 'line',
            'listing_type' => $sale ? 'sale' : 'rent',
            'perishable' => $sale && $request->boolean('perishable'),
            'lead_days' => $sale ? (int) ($v['lead_days'] ?? 0) : 0,
            'shelf_life_days' => $sale && $request->boolean('perishable') ? ($v['shelf_life_days'] ?? null) : null,
            'low_stock_at' => $sale ? ($v['low_stock_at'] ?? null) : null,
        ];
    }

    private function storeImages(Request $request, ?Product $p): array
    {
        $paths = $p?->images ?? [];
        foreach ((array) $request->file('images', []) as $file) {
            $paths[] = $file->store('products', 'public');
        }
        if ($request->boolean('clear_images')) {
            $paths = [];
        }

        return array_slice($paths, -5);
    }

    public function storeProduct(Request $request)
    {
        $data = $this->productData($request);
        $data['images'] = $this->storeImages($request, null);
        $res = Rentals::saveProduct($this->sup($request), $data);
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return redirect()->route('supplier.products')->with('success', $res['product']->name.' saved.');
    }

    public function updateProduct(Request $request, Product $product)
    {
        $p = $this->ownProduct($request, $product);
        $data = $this->productData($request);
        $data['images'] = $this->storeImages($request, $p);
        $res = Rentals::saveProduct($this->sup($request), $data, $p);
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return redirect()->route('supplier.products')->with('success', $p->name.' updated.');
    }

    public function productStatus(Request $request, Product $product)
    {
        $p = $this->ownProduct($request, $product);
        $status = $request->validate(['status' => 'required|in:published,draft,hidden,archived'])['status'];
        if ($status === 'archived' && inv()->reservedDates($p->id)) {
            return back()->with('error', 'This item has upcoming accepted reservations and cannot be archived yet.');
        }
        $p->update(['status' => $status]);
        Notifier::audit('Changed listing status', $p->name.' → '.$status);

        return back()->with('success', $p->name.' is now '.$status.'.');
    }

    /* ---------- packages ---------- */

    private function ownPackage(Request $r, Package $k): Package
    {
        abort_unless($k->supplier_id === $this->sup($r)->id, 404);

        return $k;
    }

    public function packages(Request $request)
    {
        $list = Package::where('supplier_id', $this->sup($request)->id)->with('supplier')->orderBy('sort')->orderBy('id')->get();

        return view('supplier.packages', ['list' => $list]);
    }

    private function packageForm(Request $request, Package $k)
    {
        $prods = Product::where('supplier_id', $this->sup($request)->id)->where('status', '!=', 'archived')->orderBy('name')->get();

        return view('supplier.package-form', [
            'k' => $k, 'events' => array_keys(Ai::EVENTS), 'modes' => Packages::MODES,
            'def' => $k->exists ? Packages::def($k) : Packages::normalize(['pricing' => ['mode' => 'sum_discount', 'discountPct' => 10], 'components' => []]),
            'prods' => $prods->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->listing_type, 'price' => $p->basePrice(), 'unit' => $p->unitWord(), 'stock' => $p->total_stock])->values(),
        ]);
    }

    public function createPackage(Request $request)
    {
        return $this->packageForm($request, new Package(['events' => [], 'active' => true, 'emoji' => '🎉']));
    }

    public function editPackage(Request $request, Package $package)
    {
        return $this->packageForm($request, $this->ownPackage($request, $package));
    }

    /** The builder posts the definition (pricing, components, guests) as one JSON field. */
    private function defInput(Request $request): array
    {
        $raw = $request->input('def');
        $d = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($d)) {
            throw ValidationException::withMessages(['def' => 'The package contents could not be read. Reload the page and try again.']);
        }

        return ['pricing' => (array) ($d['pricing'] ?? []), 'components' => array_values((array) ($d['components'] ?? [])), 'minGuests' => $d['minGuests'] ?? null, 'maxGuests' => $d['maxGuests'] ?? null];
    }

    private function packageData(Request $request): array
    {
        $v = $request->validate([
            'name' => 'required|string|min:3|max:120', 'emoji' => 'nullable|string|max:16', 'description' => 'required|string|max:2000',
            'conditions' => 'nullable|string|max:1000', 'events' => 'nullable|array', 'events.*' => 'string|max:40',
            'def' => 'required', 'draft' => 'nullable|boolean',
        ]);

        return array_merge($this->defInput($request), [
            'name' => trim($v['name']), 'emoji' => $v['emoji'] ?? '', 'description' => trim($v['description']), 'conditions' => trim((string) ($v['conditions'] ?? '')),
            'events' => $v['events'] ?? [], 'draft' => $request->boolean('draft'),
        ]);
    }

    public function storePackage(Request $request)
    {
        $res = Rentals::savePackage($this->sup($request), $this->packageData($request));
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return redirect()->route('supplier.packages')->with('success', $res['package']->is_draft ? 'Draft saved. Customers cannot see it until you publish it.' : 'Package published.');
    }

    public function updatePackage(Request $request, Package $package)
    {
        $res = Rentals::savePackage($this->sup($request), $this->packageData($request), $this->ownPackage($request, $package));
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return redirect()->route('supplier.packages')->with('success', $res['package']->is_draft ? 'Draft saved.' : 'Package updated. Requests already booked keep the contents and price they were booked with.');
    }

    /** Live price + warnings while the supplier edits (default options, or a sample selection). */
    public function packagePrice(Request $request)
    {
        $v = Packages::validate($this->sup($request), $this->defInput($request));
        $def = $v['def'];
        $pp = Packages::price($def, (array) $request->input('sel', []) ?: null);

        return response()->json([
            'ok' => $v['ok'], 'error' => $v['error'], 'warnings' => $def['components'] ? Packages::stockWarnings($def) : [],
            'package' => $pp['package'], 'packageText' => money($pp['package']), 'perDayText' => money($pp['perDay']), 'onceText' => money($pp['once']),
            'separateText' => money($pp['separate']), 'savings' => $pp['savings'], 'savingsText' => money($pp['savings']),
            'usesGuests' => Packages::usesGuests($def), 'guests' => $pp['sel']['guests'],
            'rows' => array_map(fn ($r) => ['group' => $r['group'], 'kind' => $r['kind'], 'name' => $r['name'], 'qty' => $r['qty'], 'cost' => money($r['cost']), 'charge' => money($r['charge'])], $pp['rows']),
        ]);
    }

    /** What the customer will see (contents + choices), rendered from the unsaved definition. */
    public function previewPackage(Request $request)
    {
        $def = Packages::validate($this->sup($request), $this->defInput($request))['def'];
        $def['name'] = (string) $request->input('name', '');

        return view('market._package-contents', ['def' => $def, 'pp' => Packages::price($def), 'interactive' => false]);
    }

    public function duplicatePackage(Request $request, Package $package)
    {
        $copy = Rentals::duplicatePackage($this->ownPackage($request, $package));
        Notifier::audit('Duplicated package', $package->name);

        return redirect()->route('supplier.packages.edit', $copy)->with('success', 'Copy created as a draft. Rename it and publish when ready.');
    }

    public function movePackage(Request $request, Package $package)
    {
        $dir = $request->validate(['dir' => 'required|in:up,down'])['dir'];
        Rentals::movePackage($this->ownPackage($request, $package), $dir);

        return back();
    }

    public function packageActive(Request $request, Package $package)
    {
        $k = $this->ownPackage($request, $package);
        if ($k->is_draft) {
            return back()->with('error', 'Open the draft and publish it first.');
        }
        $k->update(['active' => ! $k->active]);
        inv()->flush();
        Notifier::audit($k->active ? 'Activated package' : 'Deactivated package', $k->name);

        return back()->with('success', $k->name.($k->active ? ' is visible to customers again.' : ' is hidden from customers.'));
    }

    public function deletePackage(Request $request, Package $package)
    {
        $k = $this->ownPackage($request, $package);
        $name = $k->name;
        Rentals::deletePackage($k);

        return redirect()->route('supplier.packages')->with('success', $name.' deleted. Booked requests keep their copy of it.');
    }

    /* ---------- inventory ---------- */

    public function inventory(Request $request)
    {
        $date = $request->query('date') ?: today_iso();
        $prods = Product::where('supplier_id', $this->sup($request)->id)->where('status', '!=', 'archived')->with(['holds' => fn ($q) => $q->orderBy('start_date'), 'movements' => fn ($q) => $q->limit(200)])->orderBy('name')->get();
        inv()->primeProducts($prods);

        return view('supplier.inventory', ['prods' => $prods, 'date' => $date]);
    }

    public function restock(Request $request, Product $product)
    {
        $p = $this->ownProduct($request, $product);
        $data = $request->validate(['qty' => 'required|integer|min:1|max:100000', 'note' => 'nullable|string|max:255']);
        $res = Rentals::restock($p, (int) $data['qty'], (string) ($data['note'] ?? ''));

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? $p->name.' restocked: '.$res['product']->stockOnHand().' '.$p->unitWord().' on hand.' : $res['error']);
    }

    public function addHold(Request $request, Product $product)
    {
        $p = $this->ownProduct($request, $product);
        $data = $request->validate(['type' => 'required|in:maintenance,setaside', 'qty' => 'required|integer|min:1', 'start_date' => 'required|date', 'end_date' => 'nullable|date', 'note' => 'nullable|string|max:255']);
        $res = Rentals::addHold($p, $data);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Hold added for '.$p->name.'.' : $res['error']);
    }

    public function releaseHold(Request $request, Hold $hold)
    {
        $this->ownProduct($request, $hold->product);
        $hold->delete();
        inv()->flush();

        return back()->with('success', 'Hold released — units are available again.');
    }

    public function adjustCondition(Request $request, Product $product)
    {
        $p = $this->ownProduct($request, $product);
        $data = $request->validate(['field' => 'required|in:damaged,missing', 'value' => 'required|integer|min:0']);
        $res = Rentals::adjustCondition($p, $data['field'], (int) $data['value']);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? ucfirst($data['field']).' count updated.' : $res['error']);
    }
}
