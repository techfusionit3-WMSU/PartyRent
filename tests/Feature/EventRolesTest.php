<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\EventBooking;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Event company roles (2026-11-22): Event Coordinator, Finance and Event Crew each have their own module; a booking
 * has a coordinator; crew see the parts their team works; notifications go to the role that acts on them.
 */
class EventRolesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Supplier::where('name', 'Grand Fiesta Events Co.')->firstOrFail();
    }

    private function as(string $email): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => 'customer']);
    }

    public function test_each_role_lands_in_its_own_module(): void
    {
        $this->as('coordinator@grandfiesta.ph')->post('/mode', ['mode' => 'supplier'])->assertRedirect(route('business.coordinator'));
        $this->get(route('business.coordinator'))->assertOk()->assertSee('My Desk')->assertSee('Event Coordinator')->assertSee('EVT-10001')->assertSee('EVT-10003');
        $this->get(route('business.payments'))->assertRedirect(route('business.coordinator')); // money is Finance's

        $this->as('finance@grandfiesta.ph')->post('/mode', ['mode' => 'supplier'])->assertRedirect(route('business.payments'));

        $this->as('crew@grandfiesta.ph')->post('/mode', ['mode' => 'supplier'])->assertRedirect(route('business.crew'));
        $this->get(route('business.crew'))->assertOk()->assertSee('My Events')->assertSee('Debut')->assertSee('Call client')->assertSee('Map');
        $this->get(route('business.bookings'))->assertOk(); // crew may look at bookings (orders.view)
        $b = EventBooking::where('code', 'EVT-10001')->firstOrFail();
        $this->post(route('business.bookings.quote', $b), ['items' => [['label' => 'x', 'amount' => 1]], 'reservePct' => 20, 'depositPct' => 30, 'valid_days' => 7])->assertRedirect(route('business.crew'));

        // the owner opens every module and sees event wording on Team & Roles
        $this->actingAs($this->s->owner)->withSession(['mode' => 'supplier'])->get('/supplier/team')->assertOk()
            ->assertSee('Event Coordinator')->assertSee('Event Crew')->assertSee('Finance')->assertDontSee('Inventory Keeper');
        $this->get(route('business.dashboard'))->assertOk()->assertSee('Your modules');
    }

    public function test_owner_assigns_a_coordinator_and_notifications_go_to_the_role(): void
    {
        $ella = User::where('email', 'coordinator@grandfiesta.ph')->firstOrFail();
        $ben = User::where('email', 'crew@grandfiesta.ph')->firstOrFail();
        $nina = User::where('email', 'finance@grandfiesta.ph')->firstOrFail();
        $b = EventBooking::where('code', 'EVT-10001')->firstOrFail();
        $this->actingAs($this->s->owner)->withSession(['mode' => 'supplier'])->post(route('business.bookings.coordinator', $b), ['coordinator' => $ben->id])->assertSessionHas('error');
        $this->post(route('business.bookings.coordinator', $b), ['coordinator' => $ella->id])->assertSessionHas('success');
        $this->assertSame($ella->id, $b->fresh()->coordinator_id);
        $this->assertTrue(AppNotification::where('user_id', $ella->id)->where('title', 'You coordinate EVT-10001')->exists());
        // a coordinator cannot reassign
        $this->actingAs($ella)->withSession(['mode' => 'supplier'])->post(route('business.bookings.coordinator', $b), ['coordinator' => null])->assertRedirect();
        $this->assertSame($ella->id, $b->fresh()->coordinator_id);

        // a new inquiry goes to the coordinators (and the owner), not to crew or finance
        $this->as('maria@demo.ph')->post(route('events.store', $this->s), ['event_type' => 'Birthday', 'guests' => 40, 'venue' => 'Clubhouse, Quezon City',
            'parts' => [['date' => today()->addDays(70)->toDateString()]]])->assertRedirect();
        $this->assertTrue(AppNotification::where('user_id', $ella->id)->where('title', 'like', 'New event inquiry%')->exists());
        $this->assertTrue(AppNotification::where('user_id', $this->s->owner_id)->where('title', 'like', 'New event inquiry%')->exists());
        $this->assertFalse(AppNotification::where('user_id', $ben->id)->where('title', 'like', 'New event inquiry%')->exists());
        $this->assertFalse(AppNotification::where('user_id', $nina->id)->where('title', 'like', 'New event inquiry%')->exists());
        // crew do not see the owner's notifications either
        $this->actingAs($ben)->withSession(['mode' => 'supplier'])->get(route('notifications'))->assertOk()->assertDontSee('New event inquiry');
    }

    public function test_crew_marks_their_part_done(): void
    {
        $b = EventBooking::where('code', 'EVT-10003')->firstOrFail(); // confirmed debut, Team A (Marco + Ben)
        $b->parts()->update(['date' => today()->toDateString()]);
        $p = $b->parts()->first();
        $this->as('crew@grandfiesta.ph')->post('/mode', ['mode' => 'supplier']);
        $this->get(route('business.crew'))->assertOk()->assertSee('Mark Event done');
        $this->post(route('business.bookings.part-done', [$b, $p->id]))->assertSessionHas('success');
        $this->assertNotNull($p->fresh()->done_at);
        // the coordinator does not mark parts done (crew work)
        $p->fresh()->update(['done_at' => null]);
        $this->actingAs(User::where('email', 'coordinator@grandfiesta.ph')->firstOrFail())->withSession(['mode' => 'supplier'])
            ->post(route('business.bookings.part-done', [$b, $p->id]))->assertRedirect(route('business.coordinator'));
        $this->assertNull($p->fresh()->done_at);
    }
}
