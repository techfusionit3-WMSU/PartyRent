<?php

namespace App\Domain;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Typed categories. A name matching an existing category (any case, singular/plural) uses it; a new name
 * becomes a pending category an admin approves, merges or rejects. Until then the product is listed under
 * "Other Party Supplies".
 */
class Categories
{
    public const FALLBACK = 'other';

    private static function key(string $name): string
    {
        $k = mb_strtolower(trim(preg_replace('/\s+/', ' ', $name)));

        return preg_replace('/(es|s)$/u', '', $k);
    }

    public static function clean(string $name): string
    {
        $n = trim(preg_replace('/\s+/', ' ', $name));

        return mb_strtolower($n) === $n ? mb_convert_case($n, MB_CASE_TITLE) : $n;
    }

    /** @return array{category_id: string, requested_category_id: ?string, created: bool} */
    public static function resolve(string $name, User $by): array
    {
        $name = self::clean($name);
        $key = self::key($name);
        $match = Category::where(fn ($q) => $q->where('active', true)->orWhere('pending', true))->get()
            ->first(fn ($c) => self::key($c->name) === $key || $c->id === $key);
        if ($match && $match->active) {
            return ['category_id' => $match->id, 'requested_category_id' => null, 'created' => false];
        }
        if ($match) { // someone already asked for it: share the request
            return ['category_id' => self::FALLBACK, 'requested_category_id' => $match->id, 'created' => false];
        }
        $base = str($name)->slug()->limit(34, '')->toString() ?: 'cat';
        $id = $base;
        for ($i = 2; Category::find($id); $i++) {
            $id = $base.'-'.$i;
        }
        $other = Category::find(self::FALLBACK);
        Category::create([
            'id' => $id, 'name' => mb_substr($name, 0, 60), 'icon' => $other->icon ?? 'gift',
            'grad_from' => $other->grad_from ?? '#FBCFE8', 'grad_to' => $other->grad_to ?? '#DB2777',
            'active' => false, 'pending' => true, 'requested_by' => $by->id, 'sort' => (int) Category::max('sort') + 1,
        ]);
        Notifier::notify('admin', 'admin', 'New category requested: '.$name, ($by->shop()?->name ?? $by->name).' typed a category that does not exist yet. Approve, merge or reject it.', route('admin.categories', [], false));

        return ['category_id' => self::FALLBACK, 'requested_category_id' => $id, 'created' => true];
    }

    private static function tellRequesters(Category $c, string $title, string $body): void
    {
        $owners = Product::where('requested_category_id', $c->id)->with('supplier')->get()->pluck('supplier.owner_id')->push($c->requested_by)->filter()->unique();
        foreach ($owners as $uid) {
            Notifier::notify($uid, 'supplier', $title, $body, route('supplier.products', [], false));
        }
    }

    /** Approve as its own category (name, illustration and colors may be adjusted). */
    public static function approve(Category $c, array $data, User $by): void
    {
        self::tellRequesters($c, 'Category approved: '.($data['name'] ?? $c->name), 'Your products now appear under "'.($data['name'] ?? $c->name).'" in Browse.');
        DB::transaction(function () use ($c, $data) {
            $c->update($data + ['active' => true, 'pending' => false]);
            Product::where('requested_category_id', $c->id)->update(['category_id' => $c->id, 'requested_category_id' => null]);
        });
        Notifier::audit('Approved category', $c->name, $by);
    }

    /** Fold the request into an existing category. */
    public static function merge(Category $c, Category $into, User $by): void
    {
        self::tellRequesters($c, 'Category "'.$c->name.'" listed as '.$into->name, 'PartyRent put your products under "'.$into->name.'", which already covers them.');
        DB::transaction(function () use ($c, $into) {
            Product::where('requested_category_id', $c->id)->update(['category_id' => $into->id, 'requested_category_id' => null]);
            $c->delete();
        });
        Notifier::audit('Merged category request', $c->name.' → '.$into->name, $by);
    }

    /** Products stay in Other Party Supplies. */
    public static function reject(Category $c, string $reason, User $by): void
    {
        self::tellRequesters($c, 'Category "'.$c->name.'" not added', 'Your products stay under Other Party Supplies.'.($reason !== '' ? ' Reason: '.$reason : ''));
        DB::transaction(function () use ($c) {
            Product::where('requested_category_id', $c->id)->update(['requested_category_id' => null]);
            $c->delete();
        });
        Notifier::audit('Rejected category request', $c->name, $by);
    }
}
