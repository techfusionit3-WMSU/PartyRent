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

    /** These cover the business-shop percentage damage policy (individual shops use outcomes: DamageOutcomesTest). */
    protected function setUp(): void
    {
        parent::setUp();
        \App\Models\Supplier::query()->update(['account_type' => 'business']);
    }

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_full_rental_lifecycle(): void
    {
        $maria = $this->user('maria@demo.ph');
        $juan = $this->user('juan@demo.ph');
        $shop = Supplier::where('owner_id', $juan->id)->first();
        $table = Product::where('supplier_id', $shop->id)->where('name', 'like', 'Rectangular%')->first(); // 15 in stock, ₱180/day
        $d1 = add_days(today_iso(), 20);
        $d2 = add_days(today_iso(), 21);
        $free1 = inv()->availableOn($table->id, $d1);
        $free2 = inv()->availableOn($table->id, $d2);

        // 1. customer adds 4 tables on day 1 and 6 on day 2, then checks out once (v4: POST /checkout JSON, full payment, pickup)
        CartLine::where('user_id', $maria->id)->delete();
        $this->actingAs($maria)->withSession(['mode' => 'customer'])
            ->post('/cart', ['type' => 'item', 'ref_id' => $table->id, 'days' => json_encode([$d1 => 4, $d2 => 6])])->assertSessionHas('success');
        $ids = CartLine::where('user_id', $maria->id)->pluck('id')->all();
        $this->postJson('/checkout', [
            'shared' => ['eventName' => 'Test Party', 'eventType' => 'Birthday', 'contactName' => 'Maria Santos', 'contactPhone' => '0917 555 0101'],
            'orders' => [['supplier_id' => $shop->id, 'cart_line_ids' => $ids, 'eventDate' => $d1, 'fulfillment' => 'pickup', 'plan' => 'full', 'method' => 'bank']],
            'agree' => '1',
        ])->assertOk()->assertJsonPath('ok', true);
        $r = RentalRequest::latest('id')->firstOrFail();
        $this->assertSame('pending', $r->status);
        $this->assertEquals(1800.0, $r->pricing['rentalFee']); // (4 + 6) × 180
        $this->assertSame(0, CartLine::where('user_id', $maria->id)->count());
        $this->assertSame($free2, inv()->availableOn($table->id, $d2)); // pending requests never consume stock

        // 2. v4: no payments before the supplier accepts
        $this->post("/rentals/{$r->code}/payments", ['type' => 'full', 'amount' => 100, 'method' => 'cash'])->assertSessionHas('error');

        // 3. the supplier accepts → nothing is held until the first payment is verified (24 hours to pay)
        $this->actingAs($juan)->withSession(['mode' => 'supplier'])->post("/supplier/requests/{$r->code}/accept", ['note' => 'See you!'])->assertSessionHas('success');
        inv()->flush();
        $r->refresh();
        $this->assertSame('accepted', $r->status);
        $this->assertTrue($r->awaitingDownPayment());
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $r->pay_by->timestamp, 5);
        $this->assertSame($free1, inv()->availableOn($table->id, $d1));
        $this->assertSame($free2, inv()->availableOn($table->id, $d2));
        $total = (float) $r->fin()['total'];

        // 4. customer pays in full by bank transfer (needs proof) → pending until the shop's cashier verifies
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->post("/rentals/{$r->code}/payments", ['type' => 'full', 'amount' => $total, 'method' => 'bank', 'pay_account' => \App\Models\PayAccount::where('label', 'BDO')->value('id'), 'reference' => 'BDO-1'])
            ->assertSessionHas('warn'); // proof missing
        // a real 1×1 PNG (the GD extension isn't required)
        $png = tempnam(sys_get_temp_dir(), 'pr').'.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        $proof = new \Illuminate\Http\UploadedFile($png, 'receipt.png', 'image/png', null, true);
        $this->post("/rentals/{$r->code}/payments", ['type' => 'full', 'amount' => $total, 'method' => 'bank', 'pay_account' => \App\Models\PayAccount::where('label', 'BDO')->value('id'), 'reference' => 'BDO-1', 'proof' => $proof])
            ->assertSessionHas('success');
        $pay = Payment::where('rental_request_id', $r->id)->where('type', 'full')->firstOrFail();
        $this->assertSame('pending', $pay->status);
        $this->assertSame('Pending', $r->fresh()->fin()['status']); // v4: unverified payments don't count yet
        $this->assertEquals($total, $r->fresh()->fin()['pendingRental']);
        // overpaying is refused
        $this->post("/rentals/{$r->code}/payments", ['type' => 'balance', 'amount' => 50, 'method' => 'bank', 'reference' => 'OVER-1', 'proof' => \Illuminate\Http\UploadedFile::fake()->createWithContent('p.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='))])->assertSessionHas('error');
        // cash is paid to the shop in person and recorded by the shop: the customer has nothing to submit
        $before = Payment::count();
        $this->post("/rentals/{$r->code}/payments", ['type' => 'balance', 'amount' => 50, 'method' => 'cash'])->assertSessionHas('warn');
        $this->assertSame($before, Payment::count());

        // 5. the shop's cashier verifies → receipt issued, fully paid
        // the transfer went to PartyRent's account: the shop's cashier cannot verify it, PartyRent's cashier does
        $cashier = $this->shopCashier($r->code);
        $this->actingAs($cashier)->withSession(['mode' => 'supplier'])->post("/cashier/payments/{$pay->code}/verify")->assertSessionHas('error');
        $this->actingAs($this->platformCashier())->withSession(['mode' => 'cashier'])->post("/cashier/payments/{$pay->code}/verify")->assertSessionHas('success');
        $pay->refresh();
        $this->assertSame('verified', $pay->status);
        // the verified first payment reserves the stock per day
        inv()->flush();
        $this->assertNotNull($r->fresh()->reserved_at);
        $this->assertSame($free1 - 4, inv()->availableOn($table->id, $d1));
        $this->assertSame($free2 - 6, inv()->availableOn($table->id, $d2));
        $this->assertNotNull($pay->receipt_no);
        $this->assertSame('Fully Paid', $r->fresh()->fin()['status']);
        $this->actingAs($juan)->withSession(['mode' => 'supplier']);

        // stock can no longer be reduced below what's reserved
        $this->put("/supplier/products/{$table->id}", ['name' => $table->name, 'category_id' => $table->category_id, 'description' => $table->description, 'total_stock' => 3, 'unit' => $table->unit, 'condition' => 'good_as_new', 'status' => 'active', 'base_price' => $table->tiers[0]['price']])
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
        $this->post("/supplier/requests/{$r->code}/advance", ['to' => 'ongoing', 'receivedBy' => 'Maria'])->assertSessionHas('error'); // too early: release opens the day before the rental
        $this->travelTo(\Illuminate\Support\Carbon::parse($d1)->setTime(8, 0));
        $this->post("/supplier/requests/{$r->code}/advance", ['to' => 'ongoing'])->assertSessionHas('error'); // needs receiver
        $this->post("/supplier/requests/{$r->code}/advance", ['to' => 'ongoing', 'receivedBy' => 'Maria', 'releaseDate' => today_iso(), 'releaseQty' => '4 tables'])->assertSessionHas('success');
        $this->post("/supplier/requests/{$r->code}/advance", ['to' => 'return_pending'])->assertSessionHas('success');

        // 7. inspection: of 6 released, 5 returned (1 repairable), 1 lost; ₱250 value × 100% (default policy, condition per seed)
        $table->update(['replacement_value' => 250, 'condition' => 'brand_new']);
        inv()->flush();
        $this->post("/supplier/requests/{$r->code}/inspection", ['returnDate' => today_iso(), 'confirmed' => 1, 'items' => [$table->id => ['returned' => 5, 'repairable' => 2, 'nonRepairable' => 0, 'description' => 'Chipped tops; one missing']]])
            ->assertSessionHas('success');
        $table->refresh();
        $this->assertSame(2, $table->damaged);
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

        // earnings added to the next payout: 1,800 income − 5% commission + 500 damages taken from the deposit.
        // The deposit was paid in cash to the shop, so the shop already holds those 500: they come off what PartyRent sends.
        $po = \App\Models\Payout::where('rental_request_id', $r->id)->firstOrFail();
        $this->assertSame('pending', $po->status);
        $this->assertNull($po->payout_batch_id);
        $this->assertEquals([1800.0, 90.0, 500.0, 2210.0, 500.0, 1710.0], [$po->income, $po->commission, $po->compensation, $po->earned, $po->cash_kept, $po->net]);

        // 8. customer reviews
        $key = array_key_first(\App\Domain\Reviews::reviewables($r->fresh()));
        $this->actingAs($maria)->withSession(['mode' => 'customer'])->post("/rentals/{$r->code}/review", ['reviews' => [$key => ['rating' => 5, 'comment' => 'Great tables, very sturdy!']]])->assertSessionHas('success');
        $this->assertDatabaseHas('reviews', ['rental_request_id' => $r->id, 'rating' => 5]);
    }

    public function test_accept_is_blocked_when_stock_is_short(): void
    {
        // v4 availability is time-aware. Seeded PR-0003 (40 chairs, 13:00–18:00) fits after PR-0002's morning
        // package (50 chairs, 06:00–11:00 + 1 h recovery), so accepting it would work…
        $r = RentalRequest::where('code', 'PR-0003')->firstOrFail();
        $this->assertTrue(inv()->check($r->lines, $r->id)['ok']);
        // …but moved to 10:00–15:00 it overlaps the package and PR-0001: only 13 chairs are free then.
        $r->update(['lines' => array_map(fn ($l) => array_merge($l, ['time' => ['start' => '10:00', 'end' => '15:00']]), $r->lines)]);
        \App\Domain\BookingDays::sync($r->fresh());
        inv()->flush();
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
        \Illuminate\Support\Facades\Storage::fake('local');
        $jose = $this->user('jose@demo.ph');
        $form = [
            'app' => [
                'type' => 'individual',
                'personal' => ['fullName' => 'Jose Garcia', 'birthDate' => '1990-04-02', 'address' => '7 Sampaguita St., Marikina Heights, Marikina', 'phone' => '0923 555 0707', 'email' => 'jose@demo.ph'],
                'identity' => ['idType' => 'Philippine National ID', 'idNumber' => '1234-5678-9012'],
                'business' => ['productTypes' => 'speakers, lights'],
                'payout' => ['method' => 'ewallet', 'provider' => 'GCash', 'accountName' => 'Jose Garcia', 'accountNumber' => '0923 555 0707'],
            ],
            'name' => 'Jose Sounds', 'description' => 'Sound systems and lights for parties in Marikina.', 'logo' => '🎵',
            'phone' => '0923 555 0707', 'email' => 'jose@demo.ph', 'hours' => 'Daily 8–6',
            'shop_addr' => ['regionCode' => '130000000', 'provinceCode' => '137000000', 'cityCode' => '137402000', 'barangay' => 'Marikina Heights', 'street' => '7 Sampaguita St.'],
            'location_type' => 'home', 'lat' => 14.6520, 'lng' => 121.1100,
            'fulfillment' => 'both', 'delivery_fee' => 300, 'methods' => ['cash'], 'offerPlans' => ['full'],
            'depositType' => 'fixed', 'depositValue' => 500, 'cancelDays' => 2,
            'cancellation' => 'Free up to 2 days before.', 'returns' => 'Return clean.', 'conditions' => 'Renter pays for damage.',
        ];
        $this->actingAs($jose)->withSession(['mode' => 'customer']);
        // the government ID is required for an Individual Supplier
        $this->post('/start-selling', $form)->assertSessionHasErrors('docs');
        $this->assertNull(Supplier::where('name', 'Jose Sounds')->first());

        $id = \Illuminate\Http\UploadedFile::fake()->create('national-id.pdf', 120, 'application/pdf');
        $this->post('/start-selling', $form + ['docs' => ['gov_id' => $id]])->assertSessionHas('success');
        $s = Supplier::where('name', 'Jose Sounds')->firstOrFail();
        $this->assertSame('pending', $s->status);
        $this->assertSame('individual', $s->type);
        $this->assertSame('Marikina City', $s->city);
        $this->assertSame('137402000', $s->city_code);
        $this->assertSame(['standard', 'full'], \App\Domain\Finance::plansOf($s));
        $this->assertSame('national-id.pdf', $s->application['docs']['gov_id']['name']);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($s->application['docs']['gov_id']['path']);
        $this->assertSame('gcash', $s->payout_method); // payout account taken from the application
        $this->get('/start-selling')->assertOk()->assertSee('Pending Review')->assertSee('Individual Supplier');

        $admin = $this->user('admin@partyrent.ph');
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->post("/admin/applications/{$s->id}", ['decision' => 'approve'])->assertSessionHas('success');
        $this->assertSame('approved', $s->fresh()->status);
        $this->actingAs($jose->fresh())->withSession(['mode' => 'supplier'])->get('/supplier')->assertOk();
    }
}
