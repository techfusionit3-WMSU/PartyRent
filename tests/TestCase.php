<?php

namespace Tests;

use App\Domain\Settings;
use App\Models\RentalRequest;
use App\Models\ShopStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Settings are cached in a static; the database is rolled back between tests but the cache is not.
        Settings::flush();
        parent::setUp();
    }

    /**
     * v4: customers pay only after the supplier accepts. Mark a seeded request accepted without the stock
     * re-check (tests that need the real accept flow call Rentals::acceptRequest).
     */
    protected function accepted(string $code): RentalRequest
    {
        $r = RentalRequest::where('code', $code)->firstOrFail();
        $r->update(['status' => 'accepted', 'accepted_at' => today_iso()]);

        return $r->fresh();
    }

    /** v4: each shop has its own cashier. Give the request's shop one (the seed only has Grace at Elegant Occasion). */
    protected function shopCashier(string $code): User
    {
        $r = RentalRequest::where('code', $code)->firstOrFail();
        $existing = ShopStaff::where('supplier_id', $r->supplier_id)->where('role', 'cashier')->value('user_id');
        if ($existing) {
            return User::find($existing);
        }
        $u = User::create(['name' => 'Shop Cashier', 'email' => 'cashier'.$r->supplier_id.'@shop.test', 'phone' => '0917 000 0000', 'address' => 'Quezon City', 'roles' => ['cashier'], 'status' => 'active', 'password' => 'password']);
        $u->email_verified_at = now();
        $u->save();
        ShopStaff::create(['supplier_id' => $r->supplier_id, 'user_id' => $u->id, 'role' => 'cashier']);

        return $u;
    }
}
