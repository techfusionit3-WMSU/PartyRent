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
        $s = $r->user()->shop();
        if ($s && ! $r->attributes->get('branch_checked')) {
            \App\Domain\Branches::main($s); // stock is always kept per branch: a shop has at least its main branch
            $r->attributes->set('branch_checked', true);
        }

        return $s;
    }

    private function ownProduct(Request $r, Product $p): Product
    {
        abort_unless($p->supplier_id === $this->sup($r)->id, 404);

        return $p;
    }

    /* ---------- products ---------- */

    public function products(Request $request)
    {
        $f = $request->query('f', 'all');
        $q = trim((string) $request->query('q', ''));
        $all = Product::where('supplier_id', $this->sup($request)->id)->where('status', '!=', 'deleted')->with('category')->orderBy('id')->get();
        $list = $all->filter(fn ($p) => ($f === 'all' ? $p->status !== 'archived' : $p->status === $f) && ($q === '' || str_contains(mb_strtolower($p->name), mb_strtolower($q))))->values();
        inv()->primeProducts($list);

        return view('supplier.products', ['list' => $list, 'today' => today_iso(), 'f' => $f, 'q' => $q]);
    }

    public function createProduct(Request $request)
    {
        return view('supplier.product-form', ['p' => new Product(['listing_type' => $request->query('type') === 'sale' ? 'sale' : 'rent', 'tiers' => [['min' => 1, 'max' => null, 'price' => '']], 'unit' => $request->query('type') === 'sale' ? 'pc' : 'piece / day', 'condition' => 'Excellent', 'status' => 'active', 'delivery' => true, 'pickup' => true, 'colors' => [], 'themes' => [], 'events' => []]), 'cats' => Category::where('active', true)->orderBy('sort')->get(), 'events' => array_keys(Ai::EVENTS)]);
    }

    public function editProduct(Request $request, Product $product)
    {
        return view('supplier.product-form', ['p' => $this->ownProduct($request, $product), 'cats' => Category::where('active', true)->orderBy('sort')->get(), 'events' => array_keys(Ai::EVENTS)]);
    }

    public const COLORS = ['White', 'Pink', 'Gold', 'Champagne', 'Blue', 'Red', 'Black', 'Clear', 'Multicolor', 'Warm White', 'Silver'];

    public const THEMES = ['Classic', 'Elegant', 'Modern', 'Minimalist', 'Pastel', 'Princess', 'Kids', 'Glam', 'Garden', 'Outdoor', 'Romantic', 'Carnival', 'Rustic', 'Fun', 'Disco'];

    /**
     * Reference product form + change list C7: base price and an optional bulk discount become quantity tiers;
     * colors/themes from fixed chips plus "Others" text; optional maximum rental days, hour limits, a specific
     * time frame inside the shop's rental hours, overnight rentals and an item rental policy.
     */
    private function productData(Request $request): array
    {
        $time = ['nullable', 'regex:/^([01]\d|2[0-4]):[0-5]\d$/'];
        $v = $request->validate([
            'name' => 'required|string|min:3|max:120',
            'category_id' => 'nullable|required_without:category|exists:categories,id',
            'category' => 'nullable|required_without:category_id|string|min:2|max:60',
            'description' => 'required|string|min:10|max:2000',
            'listing_type' => 'nullable|in:rent,sale',
            'total_stock' => 'nullable|integer|min:0|max:100000',
            'qty' => 'nullable|array', 'qty.*' => 'nullable|integer|min:0|max:100000',
            'unit' => 'required|string|max:40',
            'condition' => 'nullable|in:'.implode(',', array_keys(\App\Domain\Damage::CONDITIONS)),
            'status' => 'required|in:active,inactive',
            'base_price' => 'required|numeric|min:0.01|max:10000000',
            'bulk' => 'nullable|array|max:20', 'bulk.*.min' => 'nullable|integer|min:2|max:100000', 'bulk.*.pct' => 'nullable|numeric|min:1|max:'.\App\Domain\Pricing::MAX_BULK_PCT, 'bulk.*.price' => 'nullable|numeric|min:0.01',
            'colors' => 'nullable|array|max:15', 'colors.*' => 'string|max:40', 'colors_other' => 'nullable|string|max:120',
            'themes' => 'nullable|array|max:15', 'themes.*' => 'string|max:40', 'themes_other' => 'nullable|string|max:120',
            'events' => 'nullable|array|max:15', 'events.*' => 'string|max:40',
            'delivery' => 'nullable|boolean',
            'replacement_value' => 'nullable|numeric|min:0|max:100000000',
            'rental_mode' => 'nullable|in:day,hour',
            'max_rental_days' => 'nullable|integer|min:1|max:365',
            'min_hours' => 'nullable|numeric|min:0.5|max:720', 'max_hours' => 'nullable|numeric|min:0.5|max:720',
            'time_frame' => 'nullable|boolean', 'time_from' => $time, 'time_to' => $time, 'allow_overnight' => 'nullable|boolean',
            'rental_policy' => 'nullable|string|max:1000',
            'images' => 'nullable|array|max:8', 'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:8192',
            'keep_images' => 'nullable|array', 'keep_images.*' => 'string|max:255', 'primary_image' => 'nullable|string|max:300',
            'unit_custom' => 'nullable|string|max:20|regex:/^[\pL][\pL .\-]*$/u', 'setup_minutes' => 'nullable|integer|min:0|max:1440',
            'perishable' => 'nullable|boolean',
            'lead_days' => 'nullable|integer|min:0|max:60',
            'shelf_life_days' => 'nullable|integer|min:1|max:3650',
            'low_stock_at' => 'nullable|integer|min:0|max:100000',
            'purchase_price' => 'nullable|numeric|min:0|max:100000000', 'purchased_on' => 'nullable|date|before_or_equal:today',
            'purchase_receipt' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:8192', 'clear_receipt' => 'nullable|boolean',
            'value_reason' => 'nullable|string|max:255', 'minor_fee' => 'nullable|numeric|min:0|max:100000000',
        ], ['base_price.required' => 'Enter the base price.', 'bulk.*.min.min' => 'A bulk discount starts at 2 or more pieces.', 'bulk.*.pct.min' => 'A bulk discount is at least 1% off.', 'bulk.*.pct.max' => 'A bulk discount is at most '.\App\Domain\Pricing::MAX_BULK_PCT.'% off.', 'purchased_on.before_or_equal' => 'The purchase date cannot be in the future.']);
        $sale = ($v['listing_type'] ?? 'rent') === 'sale';
        // Individual shops: purchase record → replacement value (suggested, lowered for age and condition).
        $personal = ! $this->sup($request)->isBusiness() && ! $sale;
        $buy = $personal && ($v['purchase_price'] ?? null) ? r2((float) $v['purchase_price']) : null;
        $repValue = ($v['replacement_value'] ?? null) ? r2($v['replacement_value']) : null;
        if ($personal && $repValue === null && $buy) {
            $repValue = \App\Domain\Damage::suggestedValue($buy, $v['purchased_on'] ?? null, $v['condition'] ?? 'used');
        }
        $reason = $personal ? trim((string) ($v['value_reason'] ?? '')) : '';
        if ($personal && $buy && $repValue > $buy && $reason === '') {
            throw \Illuminate\Validation\ValidationException::withMessages(['value_reason' => 'The replacement value is above the price you paid ('.money($buy).'). Say why, e.g. the current store price.']);
        }
        $hourly = ! $sale && ($v['rental_mode'] ?? 'day') === 'hour';
        // stock unit: one of the list, or the supplier's own ("Other")
        $word = trim(explode('/', (string) $v['unit'])[0]);
        if ($word === 'other') {
            $word = mb_strtolower(trim((string) ($v['unit_custom'] ?? '')));
            if ($word === '') {
                throw \Illuminate\Validation\ValidationException::withMessages(['unit_custom' => 'Type the stock unit, e.g. roll or meter.']);
            }
        }
        $unit = $sale
            ? ($word !== '' && ! str_contains((string) $v['unit'], '/') ? $word : 'pc') // a rental unit ("set / day") on a sale item → pc
            : ($word !== '' ? $word : 'piece').' / '.($hourly ? 'hour' : 'day');

        // base price + bulk discount tiers (optional, % off) → continuous tiers 1…, last open-ended; no tier above the stock
        $qty = array_map('intval', array_filter((array) ($v['qty'] ?? []), fn ($q) => $q !== null && $q !== ''));
        $total = ! $sale && $qty ? array_sum($qty) : (int) ($v['total_stock'] ?? 0);
        $tiers = \App\Domain\Pricing::tiersFrom((float) $v['base_price'], $v['bulk'] ?? [], $sale ? null : $total);
        if (is_string($tiers)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['bulk' => $tiers]);
        }

        // Typed tags: suggestions keep their spelling ("pink" → "Pink"); anything else is the supplier's own tag.
        $known = ['colors' => self::COLORS, 'themes' => self::THEMES, 'events' => array_keys(Ai::EVENTS)];
        $tags = function (string $k) use ($v, $known) {
            $typed = array_merge($v[$k] ?? [], explode(',', (string) ($v[$k.'_other'] ?? '')));
            $out = [];
            foreach ($typed as $t) {
                $t = trim(preg_replace('/\s+/', ' ', (string) $t));
                if ($t === '') {
                    continue;
                }
                $hit = collect($known[$k])->first(fn ($s) => mb_strtolower($s) === mb_strtolower($t));
                $t = $hit ?? (mb_strtolower($t) === $t ? mb_convert_case($t, MB_CASE_TITLE) : $t);
                $out[mb_strtolower($t)] = $t;
            }

            return array_values(array_slice($out, 0, 15));
        };
        $cat = ! empty($v['category']) && empty($v['category_id'])
            ? \App\Domain\Categories::resolve($v['category'], $request->user())
            : ['category_id' => $v['category_id'], 'requested_category_id' => null];
        $frame = ! $sale && $request->boolean('time_frame') && ($v['time_from'] ?? null) && ($v['time_to'] ?? null);
        if ($frame && \App\Domain\Slots::toMin($v['time_to']) <= \App\Domain\Slots::toMin($v['time_from']) && ! $request->boolean('allow_overnight')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['time_to' => 'The time frame must end later than it starts (or allow overnight rental).']);
        }
        if (($v['min_hours'] ?? null) && ($v['max_hours'] ?? null) && $v['max_hours'] < $v['min_hours']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['max_hours' => 'Maximum hours cannot be less than the minimum.']);
        }

        return [
            'listing_type' => $sale ? 'sale' : 'rent',
            'name' => trim($v['name']), 'category_id' => $cat['category_id'], 'requested_category_id' => $cat['requested_category_id'], 'description' => trim($v['description']),
            'total_stock' => $total, 'unit' => $unit, 'condition' => $sale ? 'brand_new' : ($v['condition'] ?? 'used'), 'status' => $v['status'],
            'tiers' => $tiers, 'colors' => $tags('colors'), 'themes' => $tags('themes'), 'events' => $tags('events'),
            'delivery' => $request->boolean('delivery'), 'pickup' => true,
            'replacement_value' => $sale ? null : $repValue,
            ...($personal ? [
                'purchase_price' => $buy, 'purchased_on' => $buy ? ($v['purchased_on'] ?? null) : null,
                'value_reason' => $buy && $repValue > $buy ? $reason : null,
                'minor_fee' => isset($v['minor_fee']) && $v['minor_fee'] !== '' ? r2((float) $v['minor_fee']) : null,
            ] : []),
            'rental_mode' => $hourly ? 'hour' : 'day',
            'min_hours' => ! $sale && ($v['min_hours'] ?? null) ? (float) $v['min_hours'] : ($hourly ? 1.0 : 0.0), // 0 = no minimum
            'max_hours' => $sale || empty($v['max_hours']) ? null : (float) $v['max_hours'],
            'max_rental_days' => $sale || ! $request->boolean('has_max_days') ? null : ($v['max_rental_days'] ?? null),
            'setup_minutes' => $sale ? null : (($v['setup_minutes'] ?? null) !== null && $v['setup_minutes'] !== '' ? (int) $v['setup_minutes'] : null),
            'time_from' => $frame ? $v['time_from'] : null, 'time_to' => $frame ? $v['time_to'] : null,
            'allow_overnight' => ! $sale && $request->boolean('allow_overnight'),
            'rental_policy' => trim((string) ($v['rental_policy'] ?? '')) ?: null,
            'perishable' => $sale && $request->boolean('perishable'),
            'lead_days' => $sale ? (int) ($v['lead_days'] ?? 0) : 0,
            'shelf_life_days' => $sale && $request->boolean('perishable') ? ($v['shelf_life_days'] ?? null) : null,
            'low_stock_at' => $sale ? ($v['low_stock_at'] ?? null) : null,
            'package_only' => ! $this->sup($request)->isBusiness() && $request->boolean('package_only'),
            '_qty' => $sale ? [] : $qty,
        ];
    }

    /** Stock per inventory location (reference stockByLocation). A new item's stock goes to the first location. */
    private function saveLocationQty(Product $p, array $qty): void
    {
        $locs = $p->supplier->locations()->pluck('id')->all();
        if (! $locs || $p->isSale()) {
            return;
        }
        if (! $qty) {
            if (\App\Models\LocationStock::where('product_id', $p->id)->exists()) {
                return;
            }
            $qty = [\App\Domain\Branches::main($p->supplier)->id => (int) $p->total_stock]; // a new item's stock starts at the main branch
        }
        foreach ($locs as $id) {
            \App\Models\LocationStock::updateOrCreate(['product_id' => $p->id, 'inventory_location_id' => $id], ['qty' => (int) ($qty[$id] ?? 0)]);
        }
        inv()->flush();
    }

    /**
     * Product photos (up to 8): the current ones the supplier kept (keep_images[]), then the new uploads, with the photo
     * marked primary moved to the front (it is the one shown in listings). primary_image = "keep:<path>" or "new:<n>".
     */
    private function storeImages(Request $request, ?Product $p): array
    {
        $old = $p?->images ?? [];
        $keep = $request->has('keep_images') || $p === null ? array_values(array_intersect($old, (array) $request->input('keep_images', []))) : $old;
        if ($request->boolean('clear_images')) {
            $keep = [];
        }
        $new = [];
        foreach ((array) $request->file('images', []) as $i => $file) {
            $new[$i] = $file->store('products', 'public');
        }
        $paths = array_merge($keep, array_values($new));
        $primary = (string) $request->input('primary_image', '');
        $first = str_starts_with($primary, 'keep:') ? substr($primary, 5) : (str_starts_with($primary, 'new:') ? ($new[(int) substr($primary, 4)] ?? null) : null);
        if ($first !== null && in_array($first, $paths, true)) {
            $paths = array_values(array_merge([$first], array_diff($paths, [$first])));
        }
        foreach (array_diff($old, $paths) as $gone) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($gone); // photos the supplier removed
        }

        return array_slice($paths, 0, 8);
    }

    /** Purchase receipt (individual shops): kept on the private disk, never public. */
    private function storeReceipt(Request $request, ?Product $p): array
    {
        if ($this->sup($request)->isBusiness()) {
            return [];
        }
        if ($file = $request->file('purchase_receipt')) {
            return ['purchase_receipt' => $file->store('receipts', 'local')];
        }

        return $request->boolean('clear_receipt') ? ['purchase_receipt' => null] : [];
    }

    /** The shop's own receipt. */
    public function receipt(Request $request, Product $product)
    {
        $p = $this->ownProduct($request, $product);
        abort_unless($p->purchase_receipt && \Illuminate\Support\Facades\Storage::disk('local')->exists($p->purchase_receipt), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($p->purchase_receipt);
    }

    public function storeProduct(Request $request)
    {
        $data = $this->productData($request);
        $qty = $data['_qty'];
        unset($data['_qty']);
        $data['images'] = $this->storeImages($request, null);
        $data += $this->storeReceipt($request, null);
        $res = Rentals::saveProduct($this->sup($request), $data);
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }
        $this->saveLocationQty($res['product'], $qty);

        return redirect()->route('supplier.products')->with('success', $res['product']->name.' saved.');
    }

    public function updateProduct(Request $request, Product $product)
    {
        $p = $this->ownProduct($request, $product);
        $data = $this->productData($request);
        $qty = $data['_qty'];
        unset($data['_qty']);
        // Location stock and product fields are saved together: if either is refused, neither is kept.
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $p, $data, $qty) {
                if ($qty) { // per-location quantities: each location keeps its booked allocation
                    $chk = Rentals::setLocationStock($p, $qty);
                    if (! $chk['ok']) {
                        throw new \DomainException($chk['error']);
                    }
                    $p->refresh();
                }
                $data['images'] = $this->storeImages($request, $p);
                $data += $this->storeReceipt($request, $p);
                $res = Rentals::saveProduct($this->sup($request), $data, $p);
                if (! $res['ok']) {
                    throw new \DomainException($res['error']);
                }
            });
        } catch (\DomainException $e) {
            inv()->flush();

            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('supplier.products')->with('success', $p->name.' updated.');
    }

    /**
     * Reference SP.products actions: Activate / Deactivate, Archive (refused while accepted reservations remain;
     * packages that use the item are deactivated) and Restore (back to inactive). Hidden-by-admin listings are locked.
     */
    public function productStatus(Request $request, Product $product)
    {
        $p = $this->ownProduct($request, $product);
        $to = $request->validate(['status' => 'required|in:active,inactive,archived,restore'])['status'];
        if ($p->status === 'hidden') {
            return back()->with('error', 'This listing was hidden by an administrator. You cannot republish it.');
        }
        if ($to === 'restore') {
            if ($p->status !== 'archived') {
                return back();
            }
            $p->update(['status' => 'inactive']);
            Notifier::audit('Restored product', $p->name);

            return back()->with('success', $p->name.' is restored as inactive. Activate it to show it in the marketplace again.');
        }
        if ($to === 'archived') {
            $future = array_values(array_filter(inv()->reservedDates($p->id), fn ($d) => $d >= today_iso()));
            if ($future) {
                return back()->with('error', 'Cannot archive — '.$p->name.' has accepted reservations on '.implode(', ', array_map('fmt_date', array_slice($future, 0, 3))).(count($future) > 3 ? '…' : '').'.');
            }
            $inPkg = Package::where('supplier_id', $p->supplier_id)->where('active', true)->get()
                ->filter(fn ($k) => collect(\App\Domain\Packages::def($k)['components'] ?? [])->contains(fn ($c) => (int) ($c['productId'] ?? 0) === $p->id))
                ->merge(Package::where('supplier_id', $p->supplier_id)->where('active', true)->get()->filter(fn ($k) => collect($k->items ?? [])->contains(fn ($it) => (int) ($it['productId'] ?? $it['product_id'] ?? 0) === $p->id)))
                ->unique('id');
            $p->update(['status' => 'archived']);
            $inPkg->each->update(['active' => false]);
            Notifier::audit('Archived product', $p->name);

            return back()->with('success', 'Archived.'.($inPkg->isNotEmpty() ? ' Deactivated packages: '.$inPkg->pluck('name')->implode(', ').'.' : ''));
        }
        $p->update(['status' => $to]);
        Notifier::audit('Changed listing status', $p->name.' → '.$to);

        return back()->with('success', $p->name.($to === 'active' ? ' is now active in the marketplace.' : ' is now inactive and hidden from customers.'));
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
        $sup = $this->sup($request);
        $prods = Product::where('supplier_id', $sup->id)->where('status', '!=', 'deleted')->orderBy('name')->get();

        return view('supplier.package-form', [
            'k' => $k, 'sup' => $sup, 'events' => array_keys(Ai::EVENTS), 'modes' => Packages::MODES,
            'cats' => $sup->isBusiness() ? collect() : Category::where('active', true)->orderBy('sort')->get(),
            'def' => $k->exists ? Packages::def($k) : Packages::normalize(['pricing' => ['mode' => 'sum_discount', 'discountPct' => 10], 'components' => []]),
            'prods' => $prods->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'type' => $p->listing_type, 'price' => $p->basePrice(), 'unit' => $p->unitWord(), 'stock' => $p->total_stock, 'packageOnly' => (bool) $p->package_only])->values(),
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

    /**
     * "Item just for this package" (personal accounts): a package-only item created from the package form.
     * It goes into the supplier's inventory but is never listed on its own.
     */
    public function packageOnlyItem(Request $request)
    {
        $sup = $this->sup($request);
        abort_if($sup->isBusiness(), 403);
        $v = $request->validate([
            'type' => 'required|in:rent,sale', 'name' => 'required|string|min:3|max:120', 'category_id' => 'required|exists:categories,id',
            'stock' => 'required|integer|min:1|max:100000', 'price' => 'required|numeric|min:0.01|max:10000000',
            'replacement_value' => 'required_if:type,rent|nullable|numeric|min:0.01|max:100000000', 'minor_fee' => 'nullable|numeric|min:0|max:100000000',
        ], ['replacement_value.required_if' => 'Enter the replacement value: what the customer pays if it is lost or beyond repair.', 'name.required' => 'Give the item a name.', 'price.required' => 'Enter the value of the item.', 'stock.min' => 'You need at least 1 of the item.']);
        $sale = $v['type'] === 'sale';
        $res = Rentals::saveProduct($sup, [
            'listing_type' => $v['type'], 'name' => trim($v['name']), 'category_id' => $v['category_id'],
            'description' => 'Included in a package.', 'unit' => $sale ? 'pc' : 'piece / day', 'condition' => $sale ? 'brand_new' : 'used',
            'status' => 'active', 'package_only' => true, 'total_stock' => (int) $v['stock'],
            'tiers' => [['min' => 1, 'max' => null, 'price' => (float) $v['price']]],
            'delivery' => true, 'pickup' => true, 'colors' => [], 'themes' => [], 'events' => [],
            'replacement_value' => $sale ? null : r2((float) $v['replacement_value']),
            'minor_fee' => ! $sale && isset($v['minor_fee']) && $v['minor_fee'] !== '' ? r2((float) $v['minor_fee']) : null,
        ]);
        if (! $res['ok']) {
            return response()->json(['message' => $res['error']], 422);
        }
        $p = $res['product'];
        $this->saveLocationQty($p, []);
        inv()->flush();

        return response()->json(['product' => ['id' => $p->id, 'name' => $p->name, 'type' => $p->listing_type, 'price' => $p->basePrice(), 'unit' => $p->unitWord(), 'stock' => $p->total_stock, 'packageOnly' => true]]);
    }

    /** The builder posts the definition (pricing, components, guests) as one JSON field. */
    private function defInput(Request $request): array
    {
        $raw = $request->input('def');
        $d = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($d)) {
            throw ValidationException::withMessages(['def' => 'The package contents could not be read. Reload the page and try again.']);
        }

        return ['pricing' => (array) ($d['pricing'] ?? []), 'components' => array_values((array) ($d['components'] ?? [])), 'minGuests' => $d['minGuests'] ?? null, 'maxGuests' => $d['maxGuests'] ?? null, 'stages' => array_values((array) ($d['stages'] ?? []))];
    }

    private function packageData(Request $request): array
    {
        $v = $request->validate([
            'name' => 'required|string|min:3|max:120', 'emoji' => 'nullable|string|max:16', 'description' => 'required|string|max:2000',
            'conditions' => 'nullable|string|max:1000', 'events' => 'nullable|array', 'events.*' => 'string|max:40',
            'def' => 'required', 'draft' => 'nullable|boolean',
            'tier_group' => 'nullable|string|max:60', 'tier_label' => 'nullable|string|max:30',
            'min_hours' => 'nullable|numeric|min:0.5|max:720', 'max_hours' => 'nullable|numeric|min:0.5|max:720',
            'setup_minutes' => 'nullable|integer|min:0|max:1440',
            'photo' => 'nullable|string|max:3000000', 'clear_photo' => 'nullable|boolean',
        ]);
        if (($v['min_hours'] ?? null) && ($v['max_hours'] ?? null) && $v['max_hours'] < $v['min_hours']) {
            throw ValidationException::withMessages(['max_hours' => 'Maximum hours cannot be less than the minimum.']);
        }
        // Rental event tags: picked from the list or typed (change list C8)
        $events = collect($v['events'] ?? [])->map(fn ($e) => ucwords(trim(preg_replace('/\s+/', ' ', $e))))->filter()->unique(fn ($e) => mb_strtolower($e))->take(12)->values()->all();

        return array_merge($this->defInput($request), [
            'name' => trim($v['name']), 'emoji' => $v['emoji'] ?? '', 'description' => trim($v['description']), 'conditions' => trim((string) ($v['conditions'] ?? '')),
            'events' => $events, 'draft' => $request->boolean('draft'),
            'tier_group' => trim((string) ($v['tier_group'] ?? '')) ?: null, 'tier_label' => trim((string) ($v['tier_label'] ?? '')) ?: null,
            'min_hours' => ($v['min_hours'] ?? null) ? (float) $v['min_hours'] : null, 'max_hours' => ($v['max_hours'] ?? null) ? (float) $v['max_hours'] : null,
            'setup_minutes' => isset($v['setup_minutes']) && $v['setup_minutes'] !== '' ? (int) $v['setup_minutes'] : null,
        ]);
    }

    /**
     * Package photo: the form crops it to a square in the browser (change list C8) and posts a JPEG/PNG data URL.
     * Returns the new images list, or null to keep the current one.
     */
    private function packagePhoto(Request $request): ?array
    {
        $raw = (string) $request->input('photo', '');
        if ($raw === '') {
            return $request->boolean('clear_photo') ? [] : null;
        }
        if (! preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $raw, $m) || ! ($bytes = base64_decode($m[2], true))) {
            throw ValidationException::withMessages(['photo' => 'The photo could not be read. Choose a JPG or PNG image.']);
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['photo' => 'The photo could not be read. Choose a JPG or PNG image.']);
        }
        $path = 'packages/'.\Illuminate\Support\Str::random(32).'.'.($m[1] === 'jpeg' ? 'jpg' : $m[1]);
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, $bytes);

        return [$path];
    }

    public function storePackage(Request $request)
    {
        $data = $this->packageData($request);
        $data['images'] = $this->packagePhoto($request) ?? [];
        $res = Rentals::savePackage($this->sup($request), $data);
        if (! $res['ok']) {
            return back()->withInput()->with('error', $res['error']);
        }

        return redirect()->route('supplier.packages')->with('success', $res['package']->is_draft ? 'Draft saved. Customers cannot see it until you publish it.' : 'Package published.');
    }

    public function updatePackage(Request $request, Package $package)
    {
        $k = $this->ownPackage($request, $package);
        $data = $this->packageData($request);
        if (($photo = $this->packagePhoto($request)) !== null) {
            $data['images'] = $photo;
        }
        $res = Rentals::savePackage($this->sup($request), $data, $k);
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
            'ok' => $v['ok'], 'error' => $v['error'],
            'warnings' => array_merge($def['components'] ? Packages::stockWarnings($def) : [], $def['pricing']['mode'] === 'fixed' && $pp['separate'] > 0 && $pp['package'] > $pp['separate']
                ? ['Your fixed price ('.money($pp['package']).') is higher than getting everything separately ('.money($pp['separate']).'). Customers may skip the package.'] : []),
            'package' => $pp['package'], 'packageText' => money($pp['package']), 'perDayText' => money($pp['perDay']), 'onceText' => money($pp['once']),
            'separateText' => money($pp['separate']), 'savings' => $pp['savings'], 'savingsText' => money($pp['savings']),
            'usesGuests' => Packages::usesGuests($def), 'guests' => $pp['sel']['guests'],
            'rows' => array_map(fn ($r) => ['group' => $r['group'], 'kind' => $r['kind'], 'name' => $r['name'], 'qty' => $r['qty'], 'cost' => money($r['cost']), 'charge' => money($r['charge'])], $pp['rows']),
            'stock' => $def['components'] ? $this->packageStock($def) : null,
        ]);
    }

    /**
     * Inventory card of the package form (reference packages list: "N available today · min M next 30 days ·
     * Limited by X"), worked out from the unsaved definition with the default options.
     */
    private function packageStock(array $def): array
    {
        $days = array_map(fn ($i) => add_days(today_iso(), $i), range(0, 29));
        $avail = array_map(fn ($d) => inv()->packageAvailableOn(0, $d, null, null, $def), $days);
        $limit = null;
        $u = Packages::units($def, null);
        foreach (['rent', 'sale'] as $kind) {
            foreach ($u[$kind] as $pid => $q) {
                $n = intdiv($kind === 'rent' ? inv()->availableOn($pid, today_iso()) : inv()->saleAvailable($pid), max(1, $q));
                if ($limit === null || $n < $limit['n']) {
                    $limit = ['n' => $n, 'name' => inv()->product($pid)?->name, 'per' => $q];
                }
            }
        }

        return ['today' => $avail[0], 'min30' => min($avail), 'limit' => $limit ? $limit['name'].' ('.$limit['per'].' per package)' : null, 'counts' => (bool) $limit];
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
        $archived = Rentals::deletePackage($k);

        return redirect()->route('supplier.packages')->with('success', $name.' deleted. Booked requests keep their copy of it.'
            .($archived ? ' Its package-only items are no longer listed and were moved to Archived in Products: '.implode(', ', $archived).'.' : ''));
    }

    /* ---------- inventory ---------- */

    public function inventory(Request $request)
    {
        $date = $request->query('date') ?: today_iso();
        $prods = Product::where('supplier_id', $this->sup($request)->id)->where('status', '!=', 'deleted')->with(['holds' => fn ($q) => $q->orderBy('start_date'), 'movements' => fn ($q) => $q->limit(200)])->orderBy('id')->get();
        inv()->primeProducts($prods);

        $sup = $this->sup($request);
        $conflicts = [];
        foreach (\App\Models\RentalRequest::forShop($sup)->where('status', 'pending')->get() as $r) {
            foreach (inv()->check($r->lines, $r->id)['bad'] as $bad) {
                $conflicts[] = ['r' => $r, 'b' => $bad];
            }
        }
        $holds = Hold::whereIn('product_id', $prods->pluck('id'))->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', today_iso()))->with('product')->orderBy('start_date')->get();

        // branch-level members see (and set) their own branches' stock only
        $locs = $sup->locations()->get()->when(\App\Support\BranchScope::viewIds() !== null, fn ($c) => $c->whereIn('id', \App\Support\BranchScope::viewIds())->values());

        return view('supplier.inventory', ['prods' => $prods, 'date' => $date, 'locs' => $locs, 'conflicts' => $conflicts, 'holds' => $holds,
            'orgLevel' => \App\Support\BranchScope::isOrgLevel()]);
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

    /** Damaged & missing counts together (reference condModal); the old single field/value form still works. */
    public function adjustCondition(Request $request, Product $product)
    {
        $p = $this->ownProduct($request, $product);
        if ($request->has('field')) {
            $data = $request->validate(['field' => 'required|in:damaged,missing', 'value' => 'required|integer|min:0']);
            $res = Rentals::adjustCondition($p, $data['field'], (int) $data['value']);

            return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? ucfirst($data['field']).' count updated.' : $res['error']);
        }
        $data = $request->validate(['damaged' => 'required|integer|min:0', 'missing' => 'required|integer|min:0']);
        if ($c = inv()->stockChangeConflicts($p->id, $p->total_stock, (int) $data['damaged'], (int) $data['missing'])) {
            return back()->with('error', 'This would leave too few units for accepted reservations on '.implode(', ', array_map(fn ($x) => fmt_date($x['date']), $c)).'.');
        }
        $res = Rentals::adjustCondition($p, 'damaged', (int) $data['damaged']);
        if ($res['ok']) {
            $res = Rentals::adjustCondition($p->fresh(), 'missing', (int) $data['missing']);
        }

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Inventory condition updated.' : $res['error']);
    }

    public function locationStock(Request $request, Product $product)
    {
        $p = $this->ownProduct($request, $product);
        $data = $request->validate(['qty' => 'required|array', 'qty.*' => 'integer|min:0|max:100000']);
        $ids = \App\Support\BranchScope::allowed();
        $qty = $ids === null ? $data['qty'] : array_intersect_key($data['qty'], array_flip($ids)); // other branches keep their count
        if (! $qty) {
            return back()->with('error', 'You can only set the stock of your own branch.');
        }
        $res = Rentals::setLocationStock($p, $qty);

        return back()->with($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Stock updated (total '.$res['total'].').' : $res['error']);
    }
}
