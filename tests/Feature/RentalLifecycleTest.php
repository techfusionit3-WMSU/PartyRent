<?php

namespace Tests\Feature;

use App\Models\CartLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** One rental, end to end, across customer → supplier → cashier, through the real HTTP routes. */
class RentalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_full_rental_lifecycle(): void
    {
        $maria = $this->user('maria@demo.ph');
        $juan = $this->user('juan@demo.ph');
        $cashier = $this->user('cashier@partyrent.ph');
        $shop = Supplier::where('owner_id', $juan->id)->first();
        $table = Product::where('supplier_id', $shop->id)->where('name', 'like', 'Rectangular%')->first(); // 15 in stock, ₱180/day
        $d1 = add_days(today_iso(), 20);
        $d2 = add_days(today_iso(), 21);

        // 1. customer adds 4 tables on day 1 and 6 on day 2, then checks out (full payment, pickup)
        CartLine::where('user_id', $maria->id)->delete();
        $this->actingAs($maria)->withSession(['mode' => 'customer'])
            ->post('/cart', ['type' => 'item', 'ref_id' => $table->id, 'days' => json_encode([$d1 => 4, $d2 => 6])])->assertSessionHas('success');
        $this->post('/checkout/'.$shop->id, [
            'eventName' => 'Test Party', 'eventType' => 'Birthday', 'eventDate' => $d1, 'fulfillment' => 'pickup',
            'contactName' => 'Maria Santos', 'contactPhone' => '0917 555 0101', 'arrangement' => 'full', 'method' => 'bank', 'agree' => '1',
        ])->assertRedirect();
        $r = RentalRequest::where('event_name', 'Test Party')->firstOrFail();
        $this->assertSame('pending', $r->status);
        $this->assertEquals(1800.0, $r->pricing['total']); // (4 + 6) × 180, no delivery fee
        $this->assertSame(0, CartLine::where('user_id', $maria->id)->count());
        $this->assertSame(15, inv()->availableOn($table->id, $d2)); // pending requests never consume stock

        // 2. the supplier cannot accept before the first payment (here: the full amount) is verified
        $this->actingAs($juan)->withSession(['mode' => 'supplier'])->post("/supplier/requests/{$r->code}/accept")->assertSessionHas('error', fn ($e) => str_contains($e, 'verified by the PartyRent cashier'));
        $this->assertSame('pending', $r->fresh()->status);
        $this->assertEquals(['amount' => 1800.0, 'type' => 'full', 'label' => 'Full payment'], $r->fresh()->fin()['upfront']);

        // 3. customer pays in full before acceptance by bank transfer (needs proof) → pending until verified
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->post("/rentals/{$r->code}/payments", ['type' => 'full', 'amount' => 1800, 'method' => 'bank', 'pay_account' => \App\Models\PayAccount::where('label', 'BDO')->value('id'), 'reference' => 'BDO-1'])
            ->assertSessionHas('warn'); // proof missing
        // a real 1×1 PNG (the GD extension isn't required)
        $png = tempnam(sys_get_temp_dir(), 'pr').'.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $proof = new \Illuminate\Http\UploadedFile($png, 'receipt.png', 'image/png', null, true);
        $this->post("/rentals/{$r->code}/payments", ['type' => 'full', 'amount' => 1800, 'method' => 'bank', 'pay_account' => \App\Models\PayAccount::where('label', 'BDO')->value('id'), 'reference' => 'BDO-1', 'proof' => $proof])
            ->assertSessionHas('success');
        $pay = Payment::where('rental_request_id', $r->id)->where('type', 'full')->firstOrFail();
        $this->assertSame('pending', $pay->status);
        $this->assertSame('Pending Verification', $r->fresh()->fin()['status']);
        // overpaying is refused
        $this->post("/rentals/{$r->code}/payments", ['type' => 'balance', 'amount' => 50, 'method' => 'cod'])->assertSessionHas('error');

        // 4. cashier verifies → receipt issued, fully paid
        $this->actingAs($cashier)->withSession(['mode' => 'cashier'])->post("/cashier/payments/{$pay->code}/verify")->assertSessionHas('success');
        $pay->refresh();
        $this->assertSame('verified', $pay->status);
        $this->assertNotNull($pay->receipt_no);
        $this->assertSame('Fully Paid', $r->fresh()->fin()['status']);

        $this->actingAs($cashier)->withSession(['mode' => 'cashier'])->post('/cashier/record', ['request' => $r->code, 'type' => 'deposit', 'method' => 'cash', 'amount' => 100, 'date' => today_iso(), 'reference' => 'OR-EARLY'])
            ->assertSessionHas('error'); // the deposit comes after acceptance

        // 5. now the supplier accepts → stock is reserved per day
        $this->actingAs($juan)->withSession(['mode' => 'supplier'])->post("/supplier/requests/{$r->code}/accept", ['note' => 'See you!'])->assertSessionHas('success');
        inv()->flush();
        $this->assertSame('accepted', $r->fresh()->status);
        $this->assertSame(11, inv()->availableOn($table->id, $d1));
        $this->assertSame(9, inv()->availableOn($table->id, $d2));

        // stock can no longer be reduced below what's reserved
        $this->put("/supplier/products/{$table->id}", ['name' => $table->name, 'category_id' => $table->category_id, 'description' => $table->description, 'total_stock' => 3, 'unit' => $table->unit, 'condition' => 'Good', 'status' => 'published', 'tiers' => $table->tiers])
            ->assertSessionHas('error');

        // deposit via cashier, recorded offline
        $this->actingAs($cashier)->withSession(['mode' => 'cashier']);
        $deposit = $r->fresh()->pricing['deposit'];
        $this->post('/cashier/record', ['request' => $r->code, 'type' => 'deposit', 'method' => 'cash', 'amount' => $deposit, 'date' => today_iso(), 'reference' => 'OR-TEST'])->assertSessionHas('success');

        // 6. supplier moves it through fulfillment
        $this->actingAs($juan)->withSession(['mode' => 'supplier']);
        foreach (['in_progress', 'ready_pickup'] as $to) {
            $this->post("/supplier/requests/{$r->code}/advance", ['to' => $to])->assertSessionHas('success');
        }
        $this->post("/supplier/requests/{$r->code}/advance", ['to' => 'ongoing'])->assertSessionHas('error'); // needs receiver
        $this->post("/supplier/requests/{$r->code}/advance", ['to' => 'ongoing', 'receivedBy' => 'Maria', 'releaseDate' => today_iso(), 'releaseQty' => '4 tables'])->assertSessionHas('success');
        $this->post("/supplier/requests/{$r->code}/advance", ['to' => 'return_pending'])->assertSessionHas('success');

        // 7. inspection: of 6 released, 5 returned (1 major damage), 1 missing, ₱500 replacement
        $this->post("/supplier/requests/{$r->code}/inspection", ['returnDate' => today_iso(), 'items' => [$table->id => ['returned' => 5, 'good' => 4, 'minor' => 0, 'major' => 1, 'total' => 0, 'missing' => 1, 'repairFee' => 0, 'replaceFee' => 500]]])
            ->assertSessionHas('success');
        $table->refresh();
        $this->assertSame(1, $table->damaged);
        $this->assertSame(1, $table->missing);
        $r->refresh();
        $this->assertSame('inspection', $r->status);
        $this->assertEquals(500, $r->fin()['compensation']);

        // can't complete before the deposit is settled
        $this->post("/supplier/requests/{$r->code}/complete")->assertSessionHas('error');
        $this->post("/supplier/requests/{$r->code}/settle", ['mode' => 'deducted'])->assertSessionHas('success');
        $r->refresh();
        $f = $r->fin();
        $this->assertEquals(0, $f['depositHeld']);
        $this->assertEquals(0, $f['compensationDue']);
        $this->assertEquals($deposit - 500, Payment::where('rental_request_id', $r->id)->where('type', 'deposit_refund')->value('amount'));
        $this->post("/supplier/requests/{$r->code}/complete")->assertSessionHas('success');
        $this->assertSame('completed', $r->fresh()->status);

        // escrow released: 1,800 income − 5% commission + 500 damages taken from the deposit (the rest of the deposit went back to Maria)
        $po = \App\Models\Payout::where('rental_request_id', $r->id)->firstOrFail();
        $this->assertSame('pending', $po->status);
        $this->assertEquals([1800.0, 90.0, 500.0, 2210.0], [$po->income, $po->commission, $po->compensation, $po->net]);

        // 8. customer reviews
        $key = array_key_first(\App\Domain\Reviews::reviewables($r->fresh()));
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->post("/rentals/{$r->code}/review", ['reviews' => [$key => ['rating' => 5, 'comment' => 'Great tables, very sturdy!']]])->assertSessionHas('success');
        $this->assertDatabaseHas('reviews', ['rental_request_id' => $r->id, 'rating' => 5]);
    }

    public function test_accept_is_blocked_when_stock_is_short(): void
    {
        // PR-0003 asks for 40 chairs on a day where only 13 are free (seed data).
        $juan = $this->user('juan@demo.ph');
        $this->actingAs($juan)->withSession(['mode' => 'supplier'])->post('/supplier/requests/PR-0003/accept')->assertSessionHas('error');
        $this->assertSame('pending', RentalRequest::where('code', 'PR-0003')->value('status'));
    }

    public function test_customer_cannot_rent_more_than_available(): void
    {
        $maria = $this->user('maria@demo.ph');
        $tent = Product::where('name', '20×20 ft Party Tent')->first(); // 4 units
        $this->actingAs($maria)->withSession(['mode' => 'customer'])
            ->post('/cart', ['type' => 'item', 'ref_id' => $tent->id, 'days' => json_encode([add_days(today_iso(), 30) => 99])])->assertSessionHas('error');
    }

    public function test_supplier_onboarding_and_admin_approval(): void
    {
        $jose = $this->user('jose@demo.ph');
        $this->actingAs($jose)->withSession(['mode' => 'customer'])->post('/start-selling', [
            'name' => 'Jose Sounds', 'description' => 'Sound systems and lights for parties in Marikina.', 'logo' => '🎵', 'contact_person' => 'Jose Garcia',
            'phone' => '0923 555 0707', 'email' => 'jose@demo.ph', 'hours' => 'Daily 8–6',
            'shop_addr' => ['regionCode' => '130000000', 'provinceCode' => '137000000', 'cityCode' => '137402000', 'barangay' => 'Marikina Heights', 'street' => '7 Sampaguita St.'],
            'location_type' => 'home', 'lat' => 14.6520, 'lng' => 121.1100,
            'fulfillment' => 'both', 'delivery' => ['mode' => 'zones', 'zones' => [['regionCode' => '130000000', 'provinceCode' => '137000000', 'cityCode' => '137402000', 'fee' => 300], ['regionCode' => '040000000', 'provinceCode' => '045800000', 'cityCode' => '', 'fee' => 650]]],
            'methods' => ['cod'], 'arrangements' => ['full'], 'reservationFee' => 0, 'reservationCredited' => '1',
            'downType' => 'percent', 'downValue' => 0, 'installments' => 2, 'depositType' => 'fixed', 'depositValue' => 500, 'cancelDays' => 2,
            'cancellation' => 'Free up to 2 days before.', 'returns' => 'Return clean.', 'conditions' => 'Renter pays for damage.',
        ])->assertSessionHas('success');
        $s = Supplier::where('name', 'Jose Sounds')->firstOrFail();
        $this->assertSame('pending', $s->status);
        $this->assertSame('Marikina City', $s->city);
        $this->assertSame('137402000', $s->city_code);
        // zone rows: deepest place picked decides the level
        $this->assertEquals([['level' => 'city', 'code' => '137402000', 'fee' => 300], ['level' => 'province', 'code' => '045800000', 'fee' => 650]], $s->delivery['zones']);

        $admin = $this->user('admin@partyrent.ph');
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->post("/admin/applications/{$s->id}", ['decision' => 'approve'])->assertSessionHas('success');
        $this->assertSame('approved', $s->fresh()->status);
        $this->actingAs($jose->fresh())->withSession(['mode' => 'supplier'])->get('/supplier')->assertOk();
    }
}
