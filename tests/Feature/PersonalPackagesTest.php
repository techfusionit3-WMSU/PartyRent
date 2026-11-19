<?php

namespace Tests\Feature;

use App\Domain\Ai;
use App\Domain\Packages;
use App\Domain\Rentals;
use App\Models\Package;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Personal suppliers: no stages or tiers, a "guests accommodated" capacity, and package-only items. */
class PersonalPackagesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private User $owner;

    private Supplier $s;

    private Product $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::where('email', 'juan@demo.ph')->firstOrFail();
        $this->s = $this->owner->supplier;
        $this->s->update(['account_type' => 'personal']);
        $this->item = Product::where('supplier_id', $this->s->id)->where('listing_type', 'rent')->where('status', 'active')->firstOrFail();
        $this->item->update(['total_stock' => 500, 'damaged' => 0, 'missing' => 0]);
    }

    private function supplier()
    {
        return $this->actingAs($this->owner)->withSession(['mode' => 'supplier']);
    }

    private function save(array $comps, array $data = [], ?int $guests = null): Package
    {
        $res = Rentals::savePackage($this->s, array_merge([
            'name' => 'Backyard Party', 'description' => 'Everything for a backyard party.', 'events' => ['Birthday'],
            'pricing' => ['mode' => 'fixed', 'price' => 3000], 'components' => $comps, 'maxGuests' => $guests,
        ], $data));
        $this->assertTrue($res['ok'], $res['error'] ?? '');

        return $res['package'];
    }

    private function packageOnly(): Product
    {
        $r = $this->supplier()->postJson(route('supplier.packages.item'), ['type' => 'rent', 'name' => 'Custom Name Backdrop', 'category_id' => $this->item->category_id, 'stock' => 2, 'price' => 750, 'replacement_value' => 4000, 'minor_fee' => 150])->assertOk();

        return Product::findOrFail($r->json('product.id'));
    }

    public function test_personal_form_hides_stages_and_tiers_and_asks_guest_capacity(): void
    {
        $this->supplier()->get(route('supplier.packages.create'))->assertOk()
            ->assertDontSee('Add stage')->assertDontSee('Group name')->assertSee('Guests accommodated')->assertSee('Item just for this package');

        $this->s->update(['account_type' => 'business']);
        $this->supplier()->get(route('supplier.packages.create'))->assertOk()
            ->assertSee('Add stage')->assertSee('Group name')->assertDontSee('Item just for this package');
    }

    public function test_personal_save_drops_stages_and_tiers(): void
    {
        $k = $this->save([['kind' => 'rent', 'productId' => $this->item->id, 'qty' => 2, 'stage' => 0]], [
            'stages' => [['name' => 'Ceremony']], 'tier_group' => 'Wedding', 'tier_label' => 'Gold',
        ], 60);
        $k->refresh();
        $this->assertEmpty($k->stages);
        $this->assertNull($k->tier_group);
        $this->assertSame(60, $k->max_guests);
        $this->assertNull(Packages::def($k)['components'][0]['stage']);
    }

    public function test_package_only_item_is_not_listed_but_rents_inside_the_package(): void
    {
        $po = $this->packageOnly();
        $this->assertTrue($po->package_only);
        $this->assertSame(2, (int) $po->total_stock);
        $this->assertSame(750.0, $po->basePrice());

        $k = $this->save([['kind' => 'rent', 'productId' => $this->item->id, 'qty' => 2], ['kind' => 'rent', 'productId' => $po->id, 'qty' => 1]]);

        $this->assertFalse(Product::live()->whereKey($po->id)->exists());
        $jose = User::where('email', 'jose@demo.ph')->firstOrFail();
        $this->actingAs($jose)->get(route('products.show', $po))->assertNotFound();
        $this->actingAs($jose)->get(route('suppliers.show', $this->s))->assertOk()->assertDontSee('Custom Name Backdrop');
        $this->actingAs($jose)->get(route('packages.show', $k))->assertOk()->assertSee('Custom Name Backdrop');
        // two backdrops → at most two packages a day
        $this->assertSame(2, inv()->packageAvailableOn($k->id, add_days(today_iso(), 10)));

        $this->supplier()->get(route('supplier.products'))->assertOk()->assertSee('Package only');
    }

    public function test_business_cannot_create_package_only_items(): void
    {
        $this->s->update(['account_type' => 'business']);
        $this->supplier()->postJson(route('supplier.packages.item'), ['type' => 'rent', 'name' => 'Backdrop', 'category_id' => $this->item->category_id, 'stock' => 1, 'price' => 100])->assertForbidden();
    }

    public function test_deleting_a_package_unlists_its_package_only_items(): void
    {
        $po = $this->packageOnly();
        $k = $this->save([['kind' => 'rent', 'productId' => $po->id, 'qty' => 1]]);
        $this->supplier()->delete(route('supplier.packages.delete', $k))->assertRedirect()->assertSessionHas('success', fn ($m) => str_contains($m, 'Custom Name Backdrop'));
        $this->assertSame('archived', $po->fresh()->status);
        $this->assertFalse(Product::live()->whereKey($po->id)->exists());

        // still used by another package → stays as it is
        $po2 = $this->packageOnly();
        $a = $this->save([['kind' => 'rent', 'productId' => $po2->id, 'qty' => 1]]);
        $this->save([['kind' => 'rent', 'productId' => $po2->id, 'qty' => 1]], ['name' => 'Second Party']);
        Rentals::deletePackage($a);
        $this->assertSame('active', $po2->fresh()->status);
    }

    public function test_guest_count_picks_packages_that_fit(): void
    {
        Package::query()->update(['active' => false]);
        $small = $this->save([['kind' => 'rent', 'productId' => $this->item->id, 'qty' => 1]], ['name' => 'Small Party'], 20);
        $big = $this->save([['kind' => 'rent', 'productId' => $this->item->id, 'qty' => 1]], ['name' => 'Big Party'], 100);
        $any = $this->save([['kind' => 'rent', 'productId' => $this->item->id, 'qty' => 1]], ['name' => 'Any Size Party']);

        $this->assertFalse(Packages::fitsGuests($small, 50));
        $this->assertTrue(Packages::fitsGuests($big, 50));
        $this->assertNull(Packages::fitsGuests($any, 50));

        $jose = User::where('email', 'jose@demo.ph')->firstOrFail();
        $this->actingAs($jose)->get(route('home', ['q' => 'party 50 guests', 'view' => 'packages']))->assertOk()
            ->assertSeeInOrder(['Big Party', 'Any Size Party'])->assertDontSee('Small Party')->assertSee('good for up to 100 guests');

        $plan = Ai::plan(Ai::interpret('Birthday party for 50 guests'));
        $names = array_map(fn ($x) => $x['pkg']->name, $plan['packages']);
        $this->assertSame('Big Party', $names[0]);
        $this->assertNotContains('Small Party', $names);
    }
}
