<?php

namespace App\Http\Controllers\Supplier;

use App\Domain\Ai;
use App\Domain\Notifier;
use App\Domain\Rentals;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Hold;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Http\Request;

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
        return view('supplier.product-form', ['p' => new Product(['tiers' => [['min' => 1, 'max' => null, 'price' => '']], 'unit' => 'piece / day', 'condition' => 'Excellent', 'status' => 'published', 'delivery' => true, 'pickup' => true, 'colors' => [], 'themes' => [], 'events' => []]), 'cats' => Category::where('active', true)->orderBy('sort')->get(), 'events' => array_keys(Ai::EVENTS)]);
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
            'total_stock' => 'required|integer|min:0|max:100000',
            'unit' => 'required|string|max:40',
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
        ]);
        $split = fn ($s) => array_values(array_filter(array_map(fn ($x) => ucwords(trim($x)), explode(',', (string) $s))));

        return [
            'name' => trim($v['name']), 'category_id' => $v['category_id'], 'description' => trim($v['description']),
            'total_stock' => (int) $v['total_stock'], 'unit' => $v['unit'], 'condition' => $v['condition'], 'status' => $v['status'],
            'tiers' => $v['tiers'], 'colors' => $split($v['colors'] ?? ''), 'themes' => $split($v['themes'] ?? ''), 'events' => $v['events'] ?? [],
            'delivery' => $request->boolean('delivery'), 'pickup' => true,
            'handling_fee' => r2($v['handling_fee'] ?? 0), 'handling_per' => $v['handling_per'] ?? 'line',
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

    public function packages(Request $request)
    {
        return view('supplier.packages', ['list' => Package::where('supplier_id', $this->sup($request)->id)->with('supplier')->latest()->get()]);
    }

    public function createPackage(Request $request)
    {
        return view('supplier.package-form', ['k' => new Package(['items' => [], 'events' => [], 'active' => true, 'emoji' => '🎉']), 'prods' => Product::where('supplier_id', $this->sup($request)->id)->where('status', '!=', 'archived')->orderBy('name')->get(), 'events' => array_keys(Ai::EVENTS)]);
    }

    public function editPackage(Request $request, Package $package)
    {
        abort_unless($package->supplier_id === $this->sup($request)->id, 404);

        return view('supplier.package-form', ['k' => $package, 'prods' => Product::where('supplier_id', $this->sup($request)->id)->where('status', '!=', 'archived')->orderBy('name')->get(), 'events' => array_keys(Ai::EVENTS)]);
    }

    private function packageData(Request $request): array
    {
        $v = $request->validate([
            'name' => 'required|string|min:3|max:120', 'emoji' => 'nullable|string|max:16', 'description' => 'required|string|max:2000',
            'price' => 'required|numeric|min:1', 'conditions' => 'nullable|string|max:1000', 'events' => 'nullable|array',
            'items' => 'array', 'items.*.product_id' => 'nullable|integer', 'items.*.qty' => 'nullable|integer|min:1', 'active' => 'nullable|boolean',
        ]);

        return ['name' => $v['name'], 'emoji' => $v['emoji'] ?: '🎉', 'description' => $v['description'], 'price' => (float) $v['price'], 'conditions' => $v['conditions'] ?? '', 'events' => $v['events'] ?? [], 'items' => $v['items'] ?? [], 'active' => $request->boolean('active')];
    }

    public function storePackage(Request $request)
    {
        $res = Rentals::savePackage($this->sup($request), $this->packageData($request));

        return $res['ok'] ? redirect()->route('supplier.packages')->with('success', 'Package saved.') : back()->withInput()->with('error', $res['error']);
    }

    public function updatePackage(Request $request, Package $package)
    {
        abort_unless($package->supplier_id === $this->sup($request)->id, 404);
        $res = Rentals::savePackage($this->sup($request), $this->packageData($request), $package);

        return $res['ok'] ? redirect()->route('supplier.packages')->with('success', 'Package updated.') : back()->withInput()->with('error', $res['error']);
    }

    /* ---------- inventory ---------- */

    public function inventory(Request $request)
    {
        $date = $request->query('date') ?: today_iso();
        $prods = Product::where('supplier_id', $this->sup($request)->id)->where('status', '!=', 'archived')->with(['holds' => fn ($q) => $q->orderBy('start_date')])->orderBy('name')->get();
        inv()->primeProducts($prods);

        return view('supplier.inventory', ['prods' => $prods, 'date' => $date]);
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
