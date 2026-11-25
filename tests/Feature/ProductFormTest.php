<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Add / Edit Product: stock unit (with a custom one) shown with quantities, allocated setup time, the photo gallery
 * (several photos, keep / remove / main photo), Ordering & freshness in Step 3, Enter guarded, package setup time.
 */
class ProductFormTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function juan(): static
    {
        return $this->actingAs(User::where('email', 'juan@demo.ph')->firstOrFail())->withSession(['mode' => 'supplier']);
    }

    private function form(array $over = []): array
    {
        return array_merge(['listing_type' => 'rent', 'name' => 'Rattan Peacock Chair', 'category_id' => Product::where('supplier_id', 1)->value('category_id'),
            'description' => 'Tall rattan chair for photo walls.', 'total_stock' => 12, 'unit' => 'piece', 'condition' => 'used', 'status' => 'active', 'base_price' => 450, 'delivery' => '1'], $over);
    }

    public function test_the_form_layout(): void
    {
        $html = $this->juan()->get(route('supplier.products.create'))->assertOk()->assertSee('data-no-enter', false)->assertSee('Stock unit')
            ->assertSee('Allocated setup time')->assertSee('data-gallery', false)->getContent();
        // Ordering & freshness lives in Step 3 (Stock & listing), not in Step 2 (Price)
        $this->assertMatchesRegularExpression('/data-wiz-step="Stock &amp; listing"><h3>Ordering (&amp;|&) freshness/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-wiz-step="Price"><h3>Ordering/', $html);
        // no error message on a fresh form (an empty one used to open the wizard on Step 2)
        $this->assertDoesNotMatchRegularExpression('/class="error-text"[^>]*>\s*[^<\s]/', $html);
    }

    public function test_stock_unit_custom_unit_and_setup_time(): void
    {
        $this->juan()->post(route('supplier.products.store'), $this->form(['unit' => 'set', 'setup_minutes' => 90]))->assertSessionHas('success');
        $p = Product::where('name', 'Rattan Peacock Chair')->firstOrFail();
        $this->assertSame('set / day', $p->unit);
        $this->assertSame(90, $p->setup_minutes);
        $this->assertSame('12 sets', $p->stockText(12));
        $this->assertSame('1 h 30 min', Product::minutesText(90));
        // a unit of your own
        $this->post(route('supplier.products.store'), $this->form(['name' => 'Fairy Light Strand', 'unit' => 'other', 'unit_custom' => 'Roll']))->assertSessionHas('success');
        $q = Product::where('name', 'Fairy Light Strand')->firstOrFail();
        $this->assertSame('roll / day', $q->unit);
        $this->assertSame('12 rolls', $q->stockText(12));
        $this->get(route('supplier.products'))->assertOk()->assertSee('12 rolls');
        // "Other" needs the unit typed
        $this->post(route('supplier.products.store'), $this->form(['name' => 'Mystery Unit Thing', 'unit' => 'other']))->assertSessionHasErrors('unit_custom');
        $this->assertSame('50 pcs', Product::qtyWithUnit(50, 'piece'));
        $this->assertSame('1 pc', Product::qtyWithUnit(1, 'piece'));
        $this->assertSame('8 bundles', Product::qtyWithUnit(8, 'bundle'));
    }

    /** A tiny real PNG (no GD needed). */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    public function test_photo_gallery_keep_remove_and_main_photo(): void
    {
        Storage::fake('public');
        $this->juan()->post(route('supplier.products.store'), $this->form(['images' => [$this->png('a.png'), $this->png('b.png'), $this->png('c.png')],
            'primary_image' => 'new:2']))->assertSessionHas('success');
        $p = Product::where('name', 'Rattan Peacock Chair')->firstOrFail();
        $this->assertCount(3, $p->images);
        $third = $p->images[0]; // the main photo moves to the front
        Storage::disk('public')->assertExists($third);
        // edit: keep two, drop one, add one, make the new one the main photo
        $drop = $p->images[2];
        $kept = [$p->images[0], $p->images[1]];
        $this->put(route('supplier.products.update', $p), $this->form(['keep_images' => [$p->images[0], $p->images[1]], 'images' => [$this->png('d-new.png')], 'primary_image' => 'new:0',
            'qty' => inv()->stockByLocation($p->id)]))->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertCount(3, $p->images);
        $this->assertNotContains($drop, $p->images);
        Storage::disk('public')->assertMissing($drop);
        $this->assertNotContains($p->images[0], $kept); // the newly added photo is now the main one
        $this->assertSame($kept, array_slice($p->images, 1));
        // not a photo: refused
        $this->put(route('supplier.products.update', $p), $this->form(['images' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]]))->assertSessionHasErrors('images.0');
    }

    public function test_package_setup_time(): void
    {
        $k = Package::where('supplier_id', 1)->firstOrFail();
        $this->juan()->get(route('supplier.packages.edit', $k))->assertOk()->assertSee('Allocated setup time')->assertSee('data-no-enter', false);
        $k->update(['setup_minutes' => 45]);
        $this->get(route('supplier.packages.edit', $k))->assertOk()->assertSee('value="45"', false);
    }
}
