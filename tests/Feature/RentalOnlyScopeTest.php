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

        // companies can still apply: as rental companies (no event-company fields)
        $this->as('jose@demo.ph', 'customer')->get(route('start-selling'))->assertOk()->assertSee('value="organization"', false)
            ->assertSee('For rental companies')->assertDontSee('data-for="organization">'."\n".'      <h3 class="mt-2 mb-1">What your business offers', false);
    }

    /** A company applies with company papers and a representative, then delivery, payments and policies like a shop. */
    public function test_rental_company_application(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $pdf = fn ($n) => \Illuminate\Http\UploadedFile::fake()->create($n, 40, 'application/pdf');
        $this->as('jose@demo.ph', 'customer');
        $form = [
            'app' => ['type' => 'organization',
                'company' => ['legalName' => 'Garcia Rentals Corp.', 'companyType' => 'corporation', 'branches' => 3, 'address' => '7 Sampaguita St., Marikina Heights, Marikina', 'email' => 'ops@garcia.ph', 'phone' => '0923 555 0707', 'natureOfBusiness' => 'Party and event equipment rental'],
                'representative' => ['name' => 'Jose Garcia', 'position' => 'Manager', 'idType' => 'Passport', 'email' => 'jose@demo.ph', 'phone' => '0923 555 0707'],
                'business' => ['productTypes' => 'Tents, chairs, backdrops'],
                'payout' => ['method' => 'bank', 'provider' => 'BPI', 'accountName' => 'Garcia Rentals Corp.', 'accountNumber' => '1234567890', 'financeContact' => 'Ana Garcia']],
            'docs' => ['sec_certificate' => $pdf('sec.pdf'), 'bir_cor' => $pdf('bir.pdf'), 'mayors_permit' => $pdf('permit.pdf'), 'rep_id' => $pdf('id.pdf'), 'proof_of_authority' => $pdf('auth.pdf')],
            'name' => 'Garcia Rentals', 'description' => 'Tents, chairs and backdrops for Marikina parties.', 'logo' => '🎪',
            'phone' => '0923 555 0707', 'email' => 'ops@garcia.ph', 'hours' => 'Daily 8–6',
            'shop_addr' => ['regionCode' => '130000000', 'provinceCode' => '137000000', 'cityCode' => '137402000', 'barangay' => 'Marikina Heights', 'street' => '7 Sampaguita St.'],
            'location_type' => 'shop', 'lat' => 14.6520, 'lng' => 121.1100, 'fulfillment' => 'both', 'methods' => ['cash', 'bank'],
            'cancelDays' => 5, 'cancellation' => 'Free up to 5 days before.', 'returns' => 'Return clean.', 'conditions' => 'Renter pays for damage.',
        ];
        $this->post('/start-selling/check', array_merge($form, ['step' => 3]))->assertOk(); // no event-company fields asked
        $this->post('/start-selling', $form)->assertSessionHasNoErrors()->assertSessionHas('success');

        $s = Supplier::where('name', 'Garcia Rentals')->firstOrFail();
        $this->assertSame(['organization', 'business', 'both'], [$s->type, $s->account_type, $s->fulfillment]);
        $this->assertSame(['cash', 'bank'], $s->methods);
        $this->assertSame('Tents, chairs, backdrops', $s->application['business']['productTypes']);
        $this->assertEmpty($s->business_profile);
        $this->assertFalse($s->isOrganization()); // runs as a rental shop with a team, not the event workspace
        $this->assertTrue($s->isBusiness());
    }

    public function test_rental_checkout_still_works(): void
    {
        $this->as('maria@demo.ph', 'customer')->get(route('cart'))->assertOk();
        $this->get(route('rentals.index'))->assertOk();
        $this->get(route('products.show', 1))->assertOk()->assertSee('Add to Rental List');
    }
}
