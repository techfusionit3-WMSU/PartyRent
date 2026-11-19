<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Business suppliers keep "what we offer" and their photos up to date in Shop settings; solo shops don't have them. */
class BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function profileForm(Supplier $s, array $over = []): array
    {
        return array_merge([
            'section' => 'profile', 'name' => $s->name, 'description' => 'Full-service events: styling, tents, lights and sound.', 'logo' => $s->logo,
            'phone' => '0917 555 0101', 'email' => $s->email, 'hours' => $s->hours, 'location_type' => 'home', // ignored for a business
            'shop_addr' => $s->address_parts, 'lat' => $s->lat, 'lng' => $s->lng,
            'events_catered' => ['Wedding', 'debut'], 'offers' => ['Styling & décor'], 'event_size' => '300_plus', 'packages_from' => 40000,
        ], $over);
    }

    private function photos(int $n): array
    {
        return array_map(fn ($i) => UploadedFile::fake()->create("p$i.jpg", 50, 'image/jpeg'), range(1, $n));
    }

    public function test_business_updates_offers_and_photos_in_shop_settings(): void
    {
        Storage::fake('public');
        $liza = User::where('email', 'liza@demo.ph')->firstOrFail();
        $s = $liza->supplier;
        $s->update(['type' => 'organization', 'account_type' => 'business', 'fulfillment' => 'delivery']); // delivery only: no pin needed for this test
        $this->actingAs($liza)->withSession(['mode' => 'supplier']);
        $this->get('/supplier/shop')->assertOk()->assertSee('What your business offers')->assertSee('About us')->assertDontSee('Where do you operate from?');

        // the portfolio needs at least 3 photos
        $this->post(route('supplier.shop.update'), $this->profileForm($s, ['portfolio' => $this->photos(2)]))->assertSessionHasErrors('portfolio');
        $this->post(route('supplier.shop.update'), $this->profileForm($s, ['portfolio' => $this->photos(3), 'cover_file' => UploadedFile::fake()->create('cover.jpg', 80, 'image/jpeg')]))->assertSessionHas('success');
        $s->refresh();
        $this->assertSame('shop', $s->location_type);
        $this->assertSame(['Wedding', 'Debut'], $s->business_profile['events']);
        $this->assertCount(3, $s->portfolio);
        $this->assertNotNull($s->cover_path);

        // public page leads with packages and shows the portfolio tab
        $this->get(route('suppliers.show', $s))->assertOk()->assertSee('shop-cover', false)->assertSee('Portfolio')->assertSee('300+ guests');
        $this->get(route('suppliers.show', [$s, 'tab' => 'portfolio']))->assertOk()->assertSee('shop-portfolio', false);

        // untick one saved photo and add one: still 3
        $old = $s->portfolio; // the request updates this same model instance
        $keep = array_slice($old, 1);
        $this->post(route('supplier.shop.update'), $this->profileForm($s, ['portfolio_keep' => $keep, 'portfolio' => $this->photos(1)]))->assertSessionHas('success');
        $this->assertCount(3, $s->fresh()->portfolio);
        $this->assertNotContains($old[0], $s->fresh()->portfolio);
    }

    public function test_solo_shop_has_no_business_section(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $this->assertFalse($juan->supplier->isOrganization());
        $this->actingAs($juan)->withSession(['mode' => 'supplier'])->get('/supplier/shop')->assertOk()
            ->assertDontSee('What your business offers')->assertSee('Where do you operate from?');
        $this->get('/supplier')->assertOk()->assertDontSee('Create your first event package');
    }
}
