<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Product form: categories and tags are typed, with optional suggestions; new categories wait for an admin. */
class TypedCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function as(string $email, string $mode = 'supplier'): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => $mode]);
    }

    private function save(string $email, array $over): Product
    {
        $this->as($email)->post('/supplier/products', array_merge([
            'listing_type' => 'rent', 'name' => 'Item '.uniqid(), 'description' => 'A sturdy item for parties and events.',
            'total_stock' => 5, 'unit' => 'piece / day', 'status' => 'active', 'base_price' => 500, 'condition' => 'used', 'delivery' => '1',
        ], $over))->assertSessionHas('success');

        return Product::latest('id')->firstOrFail();
    }

    public function test_typed_existing_category_and_tags_use_the_suggested_spelling(): void
    {
        $p = $this->save('juan@demo.ph', [
            'category' => 'chair', 'colors' => ['pink', 'Lavender', 'PINK'], 'themes' => ['boho', 'Modern'], 'events' => ['wedding', 'Graduation'],
        ]);
        $this->assertSame('chairs', $p->category_id);
        $this->assertNull($p->requested_category_id);
        $this->assertSame(['Pink', 'Lavender'], $p->colors);
        $this->assertSame(['Boho', 'Modern'], $p->themes);
        $this->assertSame(['Wedding', 'Graduation'], $p->events);
        $this->as('juan@demo.ph')->get("/supplier/products/{$p->id}/edit")->assertOk()
            ->assertSee('data-tags', false)->assertSee('value="Lavender"', false)->assertSee('value="Chairs"', false);
    }

    public function test_new_category_waits_for_admin_then_approve(): void
    {
        $p = $this->save('juan@demo.ph', ['category' => 'photo booths']);
        $this->assertSame('other', $p->category_id);
        $c = Category::findOrFail($p->requested_category_id);
        $this->assertTrue($c->pending);
        $this->assertFalse($c->active);
        $this->assertSame('Photo Booths', $c->name);
        $this->assertTrue(AppNotification::where('role', 'admin')->where('title', 'like', 'New category requested%')->exists());
        $this->as('juan@demo.ph')->get("/supplier/products/{$p->id}/edit")->assertSee('is waiting for PartyRent to approve it');

        // a second shop typing the same thing shares the request; it is not a Browse category yet
        $q = $this->save('liza@demo.ph', ['category' => 'Photo Booth']);
        $this->assertSame($c->id, $q->requested_category_id);
        $this->assertSame(1, Category::where('pending', true)->count());
        $this->get('/')->assertDontSee('Photo Booths');

        $this->as('admin@partyrent.ph', 'admin')->get('/admin/categories')->assertOk()->assertSee('Requested by suppliers')->assertSee('Photo Booths');
        $this->post("/admin/categories/{$c->id}/review", ['action' => 'approve', 'name' => 'Photo Booths', 'icon' => $c->icon, 'grad_from' => '#FBCFE8', 'grad_to' => '#DB2777'])->assertSessionHas('success');
        $this->assertSame($c->id, $p->fresh()->category_id);
        $this->assertSame($c->id, $q->fresh()->category_id);
        $this->assertNull($p->fresh()->requested_category_id);
        $this->assertTrue($c->fresh()->active);
        $this->assertTrue(AppNotification::where('user_id', User::where('email', 'liza@demo.ph')->value('id'))->where('title', 'like', 'Category approved%')->exists());
    }

    public function test_merge_and_reject(): void
    {
        $p = $this->save('juan@demo.ph', ['category' => 'Seat Covers']);
        $c = Category::findOrFail($p->requested_category_id);
        $this->as('admin@partyrent.ph', 'admin')->post("/admin/categories/{$c->id}/review", ['action' => 'merge', 'into' => 'decorations'])->assertSessionHas('success');
        $this->assertSame('decorations', $p->fresh()->category_id);
        $this->assertNull(Category::find($c->id));

        $q = $this->save('juan@demo.ph', ['category' => 'Mystery Things']);
        $d = Category::findOrFail($q->requested_category_id);
        $this->as('admin@partyrent.ph', 'admin')->post("/admin/categories/{$d->id}/review", ['action' => 'reject', 'reason' => 'Too vague'])->assertSessionHas('success');
        $this->assertSame('other', $q->fresh()->category_id);
        $this->assertNull($q->fresh()->requested_category_id);
        $this->assertNull(Category::find($d->id));
        // an approved category cannot be "reviewed"
        $this->post('/admin/categories/chairs/review', ['action' => 'reject'])->assertSessionHas('error');
    }
}
