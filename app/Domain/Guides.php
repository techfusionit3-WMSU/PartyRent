<?php

namespace App\Domain;

use App\Models\Package;
use App\Models\Product;
use App\Models\ShopStaff;
use App\Models\User;
use App\Support\CompanyModules;

/** First-time guides: the new shop's setup checklist and the welcome card a new employee sees in their module. */
class Guides
{
    /** Setup checklist of a solo shop (owner). Null when finished or dismissed. */
    public static function shop(User $u): ?array
    {
        $s = $u->shop();
        if (! $s || ! $u->isShopOwner() || $s->isOrganization() || $u->flag('guide.shop')) {
            return null;
        }
        $items = [
            [__('Describe your shop'), __('A few lines about what you rent out and where.'), trim((string) $s->description) !== '', route('supplier.shop')],
            [__('Add your first product or package'), __('Customers can only rent what you list.'), Product::where('supplier_id', $s->id)->exists() || Package::where('supplier_id', $s->id)->exists(), route('supplier.products.create')],
            [__('Check your payment plan'), __('How much customers pay first (down payment) and when the rest is due.'), ! empty($s->payment_plans), route('supplier.shop', ['tab' => 'plans'])],
            [__('Set your payout account'), __('Where PartyRent sends the money customers pay online.'), $s->hasPayoutAccount(), route('supplier.payouts')],
            [__('Set delivery and pickup'), __('Delivery areas and fees, or pickup only.'), ! empty($s->delivery) || ! empty($s->delivery_zones), route('supplier.shop', ['tab' => 'delivery'])],
        ];
        $done = count(array_filter($items, fn ($i) => $i[2]));

        return $done === count($items) ? null : ['items' => $items, 'done' => $done, 'total' => count($items)];
    }

    /** Welcome card for an employee the first time they open one of their modules. */
    public static function welcome(User $u, string $module): ?array
    {
        $s = $u->shop();
        if (! $s || $u->isShopOwner() || $u->flag('welcome.'.$module.'.'.$s->id)) {
            return null;
        }
        $m = CompanyModules::get($module);

        return [
            'key' => 'welcome.'.$module.'.'.$s->id,
            'title' => __('Welcome to :shop!', ['shop' => $s->name]),
            'role' => ShopStaff::rolesLabel($u->orgRoles()),
            'module' => $m['label'],
            'about' => $m['about'],
            'many' => count($u->modules()) > 1,
        ];
    }
}
