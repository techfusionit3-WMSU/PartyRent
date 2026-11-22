<?php

namespace Tests\Feature;

use App\Models\EventBooking;
use App\Models\EventPackage;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Event companies (Organization suppliers): their own workspace, event packages, and booking by
 * inquiry â†’ quotation â†’ reservation fee â†’ deposit â†’ balance â†’ done. No 1-to-1 item rentals.
 */
class BusinessEventsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->s = Supplier::where('name', 'Aquino Events Corp.')->firstOrFail(); // a pending application, approved here
        $this->s->update(['status' => 'approved', 'payout_method' => 'bank', 'payout_bank' => 'BDO', 'payout_account_name' => 'Aquino Events Corp.', 'payout_account_no' => '0012-345-678']);
        $this->owner = $this->s->owner;
        // PartyRent verified it for corporate events (event types: only verified kinds of events can be offered)
        \App\Models\SupplierEventType::create(['supplier_id' => $this->s->id, 'event_type_id' => \App\Models\EventType::where('name', 'Corporate Party')->value('id'),
            'status' => 'verified', 'claimed_max_guests' => 300, 'claimed_per_day' => 1, 'max_guests' => 300, 'per_day' => 1]);
    }

    private function asOwner(): static
    {
        return $this->actingAs($this->owner->fresh())->withSession(['mode' => 'supplier']);
    }

    private function asCustomer(string $email = 'maria@demo.ph'): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => 'customer']);
    }

    private function package(): EventPackage
    {
        return $this->s->eventPackages()->create(['name' => 'Corporate Gala Package', 'description' => 'Stage, lights, sound and styling for a corporate night.', 'price' => 120000, 'min_guests' => 100, 'max_guests' => 300, 'events' => ['Corporate Party'], 'inclusions' => ['Stage', 'Lights & sound'], 'active' => true]);
    }

    private function inquiry(string $date, string $email = 'maria@demo.ph', ?EventPackage $k = null)
    {
        return $this->asCustomer($email)->post(route('events.store', $this->s), [
            'event_package_id' => $k?->id, 'event_type' => 'Corporate Party', 'event_date' => $date, 'start_time' => '18:00', 'end_time' => '22:00',
            'guests' => 200, 'venue' => 'Grand Ballroom, Makati Shangri-La', 'city' => 'Makati', 'notes' => 'Blue and silver theme.',
        ]);
    }

    public function test_business_has_its_own_workspace_and_no_item_rentals(): void
    {
        // old item listings are hidden from customers; saving a new one never makes it live
        $this->assertSame(0, Product::where('supplier_id', $this->s->id)->where('status', 'active')->count());
        $p = Product::where('supplier_id', $this->s->id)->first();
        if ($p) {
            $p->update(['status' => 'active']);
            $this->assertSame('inactive', $p->fresh()->status);
        }

        $this->asOwner()->get('/supplier')->assertRedirect(route('business.dashboard'));
        $this->get('/supplier/products')->assertRedirect(route('business.dashboard'));
        $this->get('/supplier/inventory')->assertRedirect(route('business.dashboard'));
        $this->get('/supplier/team')->assertOk(); // team and profile are kept
        $this->get('/supplier/shop')->assertOk();
        $this->get(route('business.dashboard'))->assertOk()->assertSee('Get your business ready')->assertSee('Event Bookings')
            ->assertDontSee('Inventory Locations')->assertDontSee('Fulfillment &amp; Returns', false);
        $this->post('/mode', ['mode' => 'supplier'])->assertRedirect(route('business.dashboard'));

        // a solo rental shop cannot open the business workspace
        $this->actingAs(User::where('email', 'juan@demo.ph')->firstOrFail())->withSession(['mode' => 'supplier'])
            ->get(route('business.dashboard'))->assertRedirect(route('supplier.dashboard'));
    }

    public function test_settings_and_packages(): void
    {
        $this->asOwner()->get(route('business.settings'))->assertOk()->assertSee('Payment schedule')->assertSee('Cities and areas you serve');
        $this->get(route('business.packages.create'))->assertOk()->assertSee("What's included", false);
        $this->get('/supplier/shop?tab=delivery')->assertOk()->assertSee('Business Profile')->assertDontSee('Delivery &amp; pickup', false);
        $this->put(route('business.settings.update'), [
            'serviceAreas' => ['Makati', 'taguig'], 'travelNote' => 'Free within Metro Manila', 'setupHours' => 4, 'teardownHours' => 2,
            'maxEventsPerDay' => 1, 'minNoticeDays' => 14, 'reservePct' => 25, 'depositPct' => 25, 'depositDays' => 30, 'balanceDays' => 40,
            'reserveHours' => 48, 'quoteDays' => 7, 'cancellation' => 'Reservation fee is non-refundable.', 'rescheduling' => 'One free change.',
        ])->assertSessionHasErrors('balanceDays'); // balance cannot be due before the deposit
        $this->put(route('business.settings.update'), [
            'serviceAreas' => ['Makati', 'taguig'], 'travelNote' => 'Free within Metro Manila', 'setupHours' => 4, 'teardownHours' => 2,
            'maxEventsPerDay' => 1, 'minNoticeDays' => 14, 'reservePct' => 25, 'depositPct' => 25, 'depositDays' => 30, 'balanceDays' => 7,
            'reserveHours' => 48, 'quoteDays' => 7, 'cancellation' => 'Reservation fee is non-refundable.', 'rescheduling' => 'One free change.',
        ])->assertSessionHas('success');
        $set = $this->s->fresh()->bizSettings();
        $this->assertSame(['Makati', 'Taguig'], $set['serviceAreas']);
        $this->assertEquals(25, $set['reservePct']);

        $this->post(route('business.packages.store'), ['name' => 'Gala', 'description' => 'Too short', 'price' => 0, 'inclusions' => []])->assertSessionHasErrors(['description', 'price', 'inclusions']);
        $this->post(route('business.packages.store'), [
            'name' => 'Corporate Gala Package', 'description' => 'Stage, lights, sound and styling for a corporate night.', 'price' => 120000,
            'min_guests' => 100, 'max_guests' => 300, 'events' => ['Corporate Party'], 'inclusions' => ['Stage', 'lights & sound'],
            'photos' => [UploadedFile::fake()->create('gala.jpg', 60, 'image/jpeg')], 'active' => 1,
        ])->assertRedirect(route('business.packages'));
        $k = EventPackage::where('supplier_id', $this->s->id)->firstOrFail();
        $this->assertSame(['Corporate Party'], $k->events);
        $this->assertCount(1, $k->photos);
        $this->get(route('business.packages'))->assertOk()->assertSee('Corporate Gala Package');
        $this->get(route('business.packages.edit', $k))->assertOk()->assertSee('photos_keep[]', false);
        $this->get(route('suppliers.show', $this->s))->assertOk()->assertSee('Event packages')->assertDontSee('Get a quote'); // own page: no booking button
        $this->asCustomer()->get(route('suppliers.show', $this->s))->assertOk()->assertSee('Get a quote')->assertSee('Serves Makati')->assertDontSee('Products');
        $this->get(route('suppliers.index', ['type' => 'business']))->assertOk()->assertSee($this->s->name)->assertSee('Event company');
        $this->get(route('events.create', [$this->s, 'package' => $k->id]))->assertOk()->assertSee('Corporate Gala Package');
        $this->asOwner()->post(route('business.packages.toggle', $k))->assertSessionHas('success');
        $this->assertFalse($k->fresh()->active);
    }

    public function test_inquiry_quote_payments_and_completion(): void
    {
        $k = $this->package();
        $date = today()->addDays(60)->toDateString();
        $this->inquiry(today()->addDays(2)->toDateString(), 'maria@demo.ph', $k)->assertSessionHas('error'); // not enough notice
        $this->inquiry($date, 'maria@demo.ph', $k)->assertRedirect();
        $b = EventBooking::where('supplier_id', $this->s->id)->firstOrFail();
        $this->assertSame('inquiry', $b->status);
        $this->get(route('events.show', $b))->assertOk()->assertSee('preparing your quotation');
        $this->asCustomer('jose@demo.ph')->get(route('events.show', $b))->assertNotFound(); // not theirs

        // business answers with a quotation
        $this->asOwner()->get(route('business.bookings'))->assertOk()->assertSee($b->code)->assertSee('Send quotation');
        $this->get(route('business.bookings.show', $b))->assertOk()->assertSee('Blue and silver theme.');
        $this->post(route('business.bookings.quote', $b), ['items' => [['label' => '', 'amount' => '']], 'reservePct' => 20, 'depositPct' => 30, 'valid_days' => 7])->assertSessionHas('error');
        $this->post(route('business.bookings.quote', $b), ['items' => [['label' => 'Corporate Gala Package', 'amount' => 120000], ['label' => 'Travel', 'amount' => 5000]], 'reservePct' => 20, 'depositPct' => 30, 'valid_days' => 7, 'note' => 'Setup from 2 PM'])->assertSessionHas('success');
        $b->refresh();
        $this->assertSame('quoted', $b->status);
        $this->assertEquals(125000, $b->total());
        $this->assertEquals([25000, 37500, 62500], array_column($b->quote['schedule'], 'amount'));

        // customer accepts â†’ schedule created, date held once the reservation fee is confirmed
        $this->asCustomer()->get(route('events.show', $b))->assertOk()->assertSee('Accept quotation');
        $this->post(route('events.accept', $b))->assertSessionHas('success');
        $b->refresh();
        $this->assertSame('booked', $b->status);
        $this->assertCount(3, $b->payments);
        $res = $b->payments->firstWhere('kind', 'reservation');
        $this->get(route('events.show', $b))->assertSee('0012-345-678'); // pays straight to the business account

        $this->post(route('events.pay', [$b, $res]), ['method' => 'bank', 'reference' => 'BDO-998877'])->assertSessionHasErrors('proof');
        $this->post(route('events.pay', [$b, $res]), ['method' => 'bank', 'reference' => 'BDO-998877', 'proof' => UploadedFile::fake()->create('slip.jpg', 40, 'image/jpeg')])->assertSessionHas('success');
        $this->assertSame('pending', $res->fresh()->status);

        // another customer can still ask about that date until the fee is confirmed; after that it is full
        $this->asOwner()->get(route('business.payments'))->assertOk()->assertSee('BDO-998877');
        $this->get(route('business.payments.proof', $res))->assertOk();
        $this->post(route('business.payments.confirm', $res), ['decision' => 'confirm'])->assertSessionHas('success');
        $this->assertSame('confirmed', $b->fresh()->status);
        $this->assertNotNull($res->fresh()->receipt_no);
        $this->inquiry($date, 'jose@demo.ph')->assertSessionHas('error'); // fully booked that day

        // deposit sent with a wrong reference is sent back
        $dep = $b->payments()->where('kind', 'deposit')->firstOrFail();
        $this->asCustomer()->post(route('events.pay', [$b, $dep]), ['method' => 'gcash', 'reference' => 'WRONG-1', 'proof' => UploadedFile::fake()->create('x.png', 30, 'image/png')]);
        $this->asOwner()->post(route('business.payments.confirm', $dep), ['decision' => 'reject', 'reason' => 'No such transfer'])->assertSessionHas('success');
        $this->assertSame('due', $dep->fresh()->status);
        $this->assertSame('No such transfer', $dep->fresh()->reject_reason);
        // a reminder for the due deposit, at most once every 6 hours
        $this->post(route('business.payments.remind', $dep))->assertSessionHas('success');
        $this->post(route('business.payments.remind', $dep))->assertSessionHas('error');
        $this->assertTrue(\App\Models\AppNotification::where('user_id', $b->customer_id)->where('title', 'like', 'Payment reminder%')->exists());

        // the event can only be marked done on or after the day
        $this->post(route('business.bookings.complete', $b))->assertSessionHas('error');
        $this->travelTo(now()->addDays(61));
        $this->post(route('business.bookings.complete', $b))->assertSessionHas('success');
        $this->assertSame('completed', $b->fresh()->status);
        $this->asCustomer()->get(route('events.index'))->assertOk()->assertSee($b->code)->assertSee('Completed');
    }

    public function test_quotes_expire_and_bookings_can_be_cancelled(): void
    {
        $this->inquiry(today()->addDays(40)->toDateString())->assertRedirect();
        $b = EventBooking::where('supplier_id', $this->s->id)->firstOrFail();
        $this->asOwner()->post(route('business.bookings.quote', $b), ['items' => [['label' => 'Custom event', 'amount' => 50000]], 'reservePct' => 0, 'depositPct' => 50, 'valid_days' => 3]);
        $this->travel(5)->days();
        $this->asCustomer()->post(route('events.accept', $b))->assertSessionHas('error');
        $this->assertSame('expired', $b->fresh()->status);

        // 0% reservation fee: accepting holds the date right away; the customer may cancel
        $this->inquiry(today()->addDays(40)->toDateString())->assertRedirect();
        $c = EventBooking::where('supplier_id', $this->s->id)->latest('id')->firstOrFail();
        $this->asOwner()->post(route('business.bookings.quote', $c), ['items' => [['label' => 'Custom event', 'amount' => 50000]], 'reservePct' => 0, 'depositPct' => 50, 'valid_days' => 7]);
        $this->asCustomer()->post(route('events.accept', $c))->assertSessionHas('success');
        $this->assertSame('confirmed', $c->fresh()->status);
        $this->post(route('events.cancel', $c), ['reason' => 'Event moved abroad'])->assertSessionHas('success');
        $this->assertSame('cancelled', $c->fresh()->status);
        $this->assertSame(0, $c->payments()->count());

        // the business can decline an inquiry
        $this->inquiry(today()->addDays(45)->toDateString())->assertRedirect();
        $d = EventBooking::where('supplier_id', $this->s->id)->latest('id')->firstOrFail();
        $this->asOwner()->post(route('business.bookings.decline', $d), ['reason' => 'Fully booked'])->assertSessionHas('success');
        $this->assertSame('declined', $d->fresh()->status);
    }

    public function test_business_cashier_confirms_payments_in_the_business_workspace(): void
    {
        $u = User::where('email', 'maria@demo.ph')->firstOrFail();
        \App\Models\ShopStaff::create(['supplier_id' => $this->s->id, 'user_id' => User::where('email', 'jose@demo.ph')->value('id'), 'role' => 'cashier', 'status' => 'active', 'added_by' => $this->owner->id]);
        $cashier = User::where('email', 'jose@demo.ph')->firstOrFail();
        $this->assertFalse($cashier->isShopCashier()); // not the item-rental cashier workspace
        // an event company's cashier works in the Finance module: Event Payments is their home
        $this->actingAs($cashier)->withSession(['mode' => 'customer'])->post('/mode', ['mode' => 'supplier'])->assertRedirect(route('business.payments'));
        $this->get(route('business.payments'))->assertOk()->assertSee('Finance');
        $this->get(route('business.packages'))->assertRedirect(route('business.payments')); // cashiers don't manage packages
        $this->get(route('business.dashboard'))->assertRedirect(route('business.payments')); // the owner's overview is not theirs
        $this->assertNotNull($u);
    }
}
