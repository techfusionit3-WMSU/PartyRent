<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PartyRent rents decorations and party items; it does not book or manage events. With the event-company
 * module off (the default), nothing offers whole-event booking, and companies run as ordinary rental shops.
 */
class RentalOnlyScopeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        config(['partyrent.event_companies' => false]);
    }

    private function as(string $email, string $mode): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => $mode]);
    }

    public function test_customers_see_no_event_booking(): void
    {
        $org = Supplier::where('type', 'organization')->where('status', 'approved')->firstOrFail();
        $this->assertFalse($org->isOrganization(), 'a company is an ordinary rental shop');

        $this->as('maria@demo.ph', 'customer')->get('/')->assertOk()
            ->assertDontSee('Event companies')->assertDontSee('My events')->assertDontSee('AI event planner')->assertSee('Find items with AI');
        $this->get(route('suppliers.show', $org))->assertOk()->assertDontSee('Ask for a quotation');
        $this->get(route('suppliers.index'))->assertOk()->assertSee('Rental shops')->assertDontSee('Event companies');
        $this->get(route('suppliers.index', ['type' => 'business']))->assertOk()->assertSee($org->name); // old links still list every shop

        // the event-booking pages are closed, with a plain explanation
        $this->get(route('events.index'))->assertRedirect(route('home'))->assertSessionHas('warn', fn ($m) => str_contains($m, 'rents decorations'));
        $this->get(route('events.create', $org))->assertRedirect(route('home'));
        $this->post(route('events.store', $org), [])->assertRedirect(route('home'));
        $this->get('/ai')->assertOk()->assertSee('AI Rental Planner');
    }

    public function test_companies_work_as_rental_shops(): void
    {
        $org = Supplier::where('type', 'organization')->where('status', 'approved')->firstOrFail();
        $this->actingAs($org->owner)->withSession(['mode' => 'supplier']);
        $this->get(route('supplier.dashboard'))->assertOk();
        $this->get(route('supplier.products'))->assertOk();          // item listings are open to them again
        $this->get(route('business.dashboard'))->assertRedirect(route('home'));
    }

    public function test_admins_and_new_sellers_see_no_event_module(): void
    {
        $this->as('admin@partyrent.ph', 'admin')->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee(route('admin.events'), false)->assertDontSee(route('admin.event-types'), false);
        $this->get(route('admin.event-types'))->assertRedirect(route('home'));

        $this->as('jose@demo.ph', 'customer')->get(route('start-selling'))->assertOk()->assertDontSee('value="organization"', false);
    }

    public function test_rental_checkout_still_works(): void
    {
        $this->as('maria@demo.ph', 'customer')->get(route('cart'))->assertOk();
        $this->get(route('rentals.index'))->assertOk();
        $this->get(route('products.show', 1))->assertOk()->assertSee('Add to Rental List');
    }
}
