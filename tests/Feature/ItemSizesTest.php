<?php

namespace Tests\Feature;

use App\Domain\ItemSizes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Typical weight / packed size suggested on the product form. */
class ItemSizesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_guesses_from_name_then_category(): void
    {
        $this->assertSame('Monoblock chair', ItemSizes::guess('White Monoblock Chair', 'chairs')['label']);
        $this->assertSame('Round table (5 ft)', ItemSizes::guess('Round Table for 10 pax')['label']);
        $this->assertSame('Kiddie chair', ItemSizes::guess('Kiddie chair (pink)', 'chairs')['label']);
        $this->assertSame('Canopy tent 3 × 3 m', ItemSizes::guess('Canopy 3x3 white', 'tents')['label']);
        $this->assertSame('Chafing dish', ItemSizes::guess('Something', 'catering')['label']); // category fallback
        $this->assertNull(ItemSizes::guess('Something'));
        foreach (ItemSizes::CATEGORY_DEFAULT as $label) {
            $this->assertNotNull(collect(ItemSizes::forJs())->firstWhere('label', $label), $label);
        }
    }

    public function test_product_form_offers_the_presets(): void
    {
        $owner = \App\Models\Supplier::approved()->firstOrFail()->owner;
        $this->actingAs($owner)->withSession(['mode' => 'supplier'])->get(route('supplier.products.create'))
            ->assertOk(); // v4: delivery is priced per zone, so the form no longer asks for weight and size
    }
}
