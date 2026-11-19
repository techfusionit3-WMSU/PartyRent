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
    /** The first payment of an accepted request was verified: its items are now reserved (as Rentals::secureIfPaid does). */
    protected function reserve(RentalRequest|string $r): RentalRequest
    {
        $r = is_string($r) ? RentalRequest::where('code', $r)->firstOrFail() : $r;
        $r->update(['reserved_at' => now()]);
        inv()->flush();

        return $r->fresh();
    }

    protected function accepted(string $code): RentalRequest
    {
        $r = RentalRequest::where('code', $code)->firstOrFail();
        $r->update(['status' => 'accepted', 'accepted_at' => today_iso(), 'reserved_at' => now()]); // a booking whose down payment is in

        return $r->fresh();
    }

    /**
     * v4 single checkout (POST /checkout JSON) for the signed-in customer's rental-list lines of one shop.
     * $form uses the old field names: eventName, eventType, eventDate, fulfillment, contactName, contactPhone,
     * arrangement (→ plan), method, destinations.
     */
    protected function checkout(int $supplierId, array $form): \Illuminate\Testing\TestResponse
    {
        $ids = \App\Models\CartLine::where('user_id', auth()->id())->where('supplier_id', $supplierId)->pluck('id')->all();

        return $this->postJson('/checkout', [
            'shared' => array_intersect_key($form, array_flip(['eventName', 'eventType', 'contactName', 'contactPhone', 'instructions'])),
            'orders' => [['supplier_id' => $supplierId, 'cart_line_ids' => $ids, 'eventDate' => $form['eventDate'] ?? null, 'fulfillment' => $form['fulfillment'] ?? 'pickup',
                'plan' => $form['plan'] ?? $form['arrangement'] ?? 'full', 'method' => $form['method'] ?? 'bank', 'destinations' => $form['destinations'] ?? []]],
            'agree' => $form['agree'] ?? '1',
        ]);
    }

    /** PartyRent's own cashier (no shop): checks bank / debit transfers for every shop. */
    protected function platformCashier(): User
    {
        $u = User::firstOrCreate(['email' => 'cashier@partyrent.test'], ['name' => 'PartyRent Cashier', 'phone' => '0917 000 0001', 'address' => 'Makati City', 'roles' => ['cashier'], 'status' => 'active', 'password' => 'password']);
        $u->forceFill(['email_verified_at' => now()])->save();

        return $u;
    }

    /** v4: each shop has its own cashier. Give the request's shop one (the seed only has Grace at Elegant Occasion). */
    protected function shopCashier(string $code): User
    {
        $r = RentalRequest::where('code', $code)->firstOrFail();
        $existing = ShopStaff::where('supplier_id', $r->supplier_id)->withRole('cashier')->value('user_id');
        if ($existing) {
            return User::find($existing);
        }
        $u = User::create(['name' => 'Shop Cashier', 'email' => 'cashier'.$r->supplier_id.'@shop.test', 'phone' => '0917 000 0000', 'address' => 'Quezon City', 'roles' => ['customer'], 'status' => 'active', 'password' => 'password']);
        $u->email_verified_at = now();
        $u->save();
        ShopStaff::create(['supplier_id' => $r->supplier_id, 'user_id' => $u->id, 'role' => 'cashier']);

        return $u;
    }
}
