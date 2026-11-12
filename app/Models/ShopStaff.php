<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user working for a Business rental partner, with one role that limits the pages they can open. */
class ShopStaff extends Model
{
    protected $table = 'shop_staff';

    protected $guarded = ['id'];

    /**
     * role => [label, what they do, route-name patterns (after "supplier.") they may open].
     * PartyRent collects every customer payment, so no staff role handles money.
     */
    public const ROLES = [
        'manager' => ['Manager', 'Everything except staff, shop settings and the payout account.', ['*']],
        'coordinator' => ['Coordinator', 'Accepts and declines requests, talks to customers, replies to reviews.', ['dashboard', 'schedule', 'requests', 'requests.show', 'requests.accept', 'requests.decline', 'requests.notes', 'requests.advance', 'calendar', 'products', 'packages', 'reviews', 'reviews.reply']],
        'warehouse' => ['Warehouse', 'Prepares and releases items, receives returns, checks damage, manages stock.', ['dashboard', 'schedule', 'requests', 'requests.show', 'requests.advance', 'requests.inspection', 'requests.notes', 'products', 'inventory', 'inventory.*', 'calendar', 'damages']],
        'cashier' => ['Cashier', 'Sees payments, balances, receipts, earnings and reports. Does not take money: PartyRent collects all payments.', ['dashboard', 'requests', 'requests.show', 'payments', 'payouts', 'reports', 'reports.csv']],
    ];

    /** Pages only the owner can open. */
    public const OWNER_ONLY = ['staff', 'staff.*', 'shop.update', 'business', 'payouts.account'];

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function label(): string { return self::ROLES[$this->role][0] ?? ucfirst($this->role); }

    /** Can this role open the supplier route with this name (e.g. "supplier.requests.show")? */
    public static function allows(string $role, string $routeName): bool
    {
        $name = str_starts_with($routeName, 'supplier.') ? substr($routeName, 9) : $routeName;
        foreach (self::OWNER_ONLY as $p) {
            if (fnmatch($p, $name)) {
                return false;
            }
        }
        foreach (self::ROLES[$role][2] ?? [] as $p) {
            if (fnmatch($p, $name)) {
                return true;
            }
        }

        return false;
    }
}
