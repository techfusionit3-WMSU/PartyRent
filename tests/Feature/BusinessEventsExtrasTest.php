<?php

namespace Tests\Feature;

use App\Domain\Events;
use App\Models\AppNotification;
use App\Models\EventBooking;
use App\Models\EventPayment;
use App\Models\Review;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Event companies, part 2: pay online, reviews, calendar, daily reminders, admin view and sending online money on. */
class BusinessEventsExtrasTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Supplier::where('name', 'Grand Fiesta Events Co.')->firstOrFail(); // demo event company
        config(['services.xendit.secret_key' => '', 'services.paymongo.fake' => true]); // practice checkout
    }

    private function maria(): static
    {
        return $this->actingAs(User::where('email', 'maria@demo.ph')->firstOrFail())->withSession(['mode' => 'customer']);
    }

    private function owner(): static
    {
        return $this->actingAs($this->s->owner)->withSession(['mode' => 'supplier']);
    }

    public function test_demo_company_is_ready(): void
    {
        $this->assertTrue($this->s->isOrganization());
        $this->assertSame(4, $this->s->eventPackages()->count());
        $this->assertSame(['inquiry', 'quoted', 'confirmed'], EventBooking::where('supplier_id', $this->s->id)->orderBy('id')->pluck('status')->all());
        $this->get(route('suppliers.index', ['type' => 'business']))->assertOk()->assertSee('Grand Fiesta Events Co.');
        $this->owner()->get(route('business.dashboard'))->assertOk()->assertSee('Upcoming events')->assertDontSee('Get your business ready');
    }

    public function test_pay_an_installment_online_and_admin_sends_it_on(): void
    {
        $b = EventBooking::where('code', 'EVT-10003')->firstOrFail();
        $dep = $b->payments()->where('kind', 'deposit')->firstOrFail();
        $this->maria()->get(route('events.show', $b))->assertOk()->assertSee('online');
        $this->post(route('events.online', [$b, $dep]))->assertRedirect(route('events.online.practice', [$b, $dep]));
        $this->get(route('events.online.practice', [$b, $dep]))->assertOk()->assertSee('no real money');
        $this->post(route('events.online.practice.pay', [$b, $dep]), ['result' => 'fail'])->assertSessionHas('error');
        $this->assertSame('due', $dep->fresh()->status);
        $this->post(route('events.online.practice.pay', [$b, $dep]), ['result' => 'ok'])->assertSessionHas('success');
        $dep->refresh();
        $this->assertSame(['paid', 'online', 'practice'], [$dep->status, $dep->method, $dep->gateway]);
        $this->assertNull($dep->confirmed_by); // confirmed by the gateway, not the business
        $this->assertTrue(AppNotification::where('user_id', $this->s->owner_id)->where('title', 'like', 'Paid online%')->exists());
        $this->owner()->get(route('business.payments'))->assertOk()->assertSee('PartyRent sends it');

        // PartyRent sends it on to the business
        $admin = User::where('email', 'admin@partyrent.ph')->firstOrFail();
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->get(route('admin.events', ['tab' => 'tosend']))->assertOk()->assertSee('Grand Fiesta Events Co.')->assertSee('0034-5566-7788');
        $this->get(route('admin.events'))->assertOk()->assertSee('EVT-10001');
        $this->post(route('admin.events.remit', $dep))->assertSessionHas('success');
        $this->assertNotNull($dep->fresh()->remitted_at);
        $this->post(route('admin.events.remit', $dep))->assertSessionHas('error'); // only once
        // an admin without payout rights can look but not send
        $paolo = User::where('email', 'paolo.admin@partyrent.ph')->firstOrFail();
        $this->actingAs($paolo)->withSession(['mode' => 'admin'])->get(route('admin.events'))->assertOk();
        $this->post(route('admin.events.remit', $b->payments()->where('kind', 'reservation')->first()))->assertForbidden();
    }

    public function test_review_after_the_event(): void
    {
        $b = EventBooking::where('code', 'EVT-10003')->firstOrFail();
        $this->maria()->post(route('events.review', $b), ['rating' => 5, 'comment' => 'Beautiful debut, very organized team!'])->assertSessionHas('error'); // not done yet
        $b->update(['status' => 'completed', 'completed_at' => now()]);
        $this->post(route('events.review', $b), ['rating' => 5, 'comment' => 'short'])->assertSessionHasErrors('comment');
        $this->post(route('events.review', $b), ['rating' => 5, 'comment' => 'Beautiful debut, very organized team!'])->assertSessionHas('success');
        $this->post(route('events.review', $b), ['rating' => 4, 'comment' => 'Trying to review again here.'])->assertSessionHas('error');
        $rv = Review::where('event_booking_id', $b->id)->firstOrFail();
        $this->assertTrue($rv->isVerified());
        $this->assertSame('Debut event', $rv->itemName());
        $this->assertEquals(5.0, $this->s->fresh()->rating);
        $this->get(route('events.show', $b))->assertSee('Your review');
        $this->get(route('suppliers.show', [$this->s, 'tab' => 'reviews']))->assertOk()->assertSee('Beautiful debut');
        // the business answers from its Reviews page
        $this->owner()->get('/supplier/reviews?tab=all')->assertOk()->assertSee('Beautiful debut');
    }

    public function test_calendar_and_daily_reminders(): void
    {
        $b = EventBooking::where('code', 'EVT-10003')->firstOrFail();
        $this->owner()->get(route('business.calendar', ['m' => $b->event_date->format('Y-m')]))->assertOk()->assertSee('Debut · Maria')->assertSee('Full');
        $this->get(route('business.calendar', ['m' => 'nonsense']))->assertOk();

        // deposit due in 3 days → one reminder that day, not twice
        $dep = $b->payments()->where('kind', 'deposit')->firstOrFail();
        $dep->update(['due_date' => today()->addDays(3)]);
        $this->assertSame(1, Events::daily()['reminders']);
        $this->assertSame(0, Events::daily()['reminders']);
        $this->artisan('partyrent:events-daily')->assertSuccessful();
        // the old quotation expires on its own
        $this->travel(10)->days();
        Events::daily();
        $this->assertSame('expired', EventBooking::where('code', 'EVT-10002')->value('status'));
        $this->assertTrue(AppNotification::where('user_id', $b->customer_id)->where('title', 'like', 'Payment reminder%')->exists());
    }

    public function test_xendit_webhook_settles_an_event_payment(): void
    {
        $b = EventBooking::where('code', 'EVT-10003')->firstOrFail();
        $dep = $b->payments()->where('kind', 'deposit')->firstOrFail();
        $dep->update(['gateway' => 'xendit', 'gateway_ref' => 'inv_123', 'gateway_url' => 'https://checkout.xendit.co/x']);
        $res = \App\Domain\Xendit::handleCallback(['id' => 'inv_123', 'external_id' => $dep->code, 'status' => 'PAID', 'paid_amount' => $dep->amount, 'payment_channel' => 'GCASH']);
        $this->assertSame('paid', $res);
        $this->assertSame('paid', $dep->fresh()->status);
        $this->assertSame('done', \App\Domain\Xendit::handleCallback(['id' => 'inv_123', 'external_id' => $dep->code, 'status' => 'PAID', 'paid_amount' => $dep->amount]));
        $this->assertSame('unknown', \App\Domain\Xendit::handleCallback(['id' => 'inv_nope', 'external_id' => 'X', 'status' => 'PAID']));
        $this->assertInstanceOf(EventPayment::class, $dep);
    }
}
