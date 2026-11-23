<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Store History: who (name + shop role), what, when, old → new, append-only, one line per action. */
class StoreHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function as(string $email, string $mode = 'supplier'): static
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => $mode]);
    }

    public function test_an_owner_change_is_recorded_once_with_type_category_role_and_old_new_values(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $k = Package::where('supplier_id', $juan->supplier->id)->where('is_draft', false)->where('active', true)->firstOrFail();
        $before = AuditLog::where('supplier_id', $juan->supplier->id)->count();

        $this->as('juan@demo.ph')->post(route('supplier.packages.active', $k))->assertRedirect();

        $rows = AuditLog::where('supplier_id', $juan->supplier->id)->where('id', '>', 0)->get()->slice($before);
        $this->assertCount(1, $rows, 'one line per action, no duplicates');
        $a = $rows->first();
        $this->assertSame(['updated', 'packages', 'Owner', $juan->id], [$a->type, $a->category, $a->role_label, $a->actor_id]);
        $this->assertSame($k->name, $a->ref);
        $this->assertSame([['field' => 'Active', 'old' => 'Yes', 'new' => 'No']], $a->changes[0]['fields']);
        $this->assertSame('Package', $a->changes[0]['record']);

        // shown on the Store History page, with the values
        $this->as('juan@demo.ph')->get(route('supplier.activity'))->assertOk()->assertSee('Store History')
            ->assertSee($juan->name)->assertSee('Owner')->assertSee('What changed')->assertSee('Before')->assertSee($k->name);
    }

    public function test_an_employee_shows_with_their_shop_role(): void
    {
        $shop = User::where('email', 'juan@demo.ph')->firstOrFail()->supplier;
        $rico = User::create(['name' => 'Rico Keeper', 'email' => 'keeper@shop.test', 'phone' => '0917 000 0001', 'address' => 'Quezon City', 'roles' => ['customer'], 'status' => 'active', 'password' => 'password']);
        $rico->email_verified_at = now();
        $rico->save();
        \App\Models\ShopStaff::create(['supplier_id' => $shop->id, 'user_id' => $rico->id, 'role' => 'inventory', 'roles' => ['inventory'], 'status' => 'active', 'added_by' => $shop->owner_id]);
        $rico = $rico->fresh();
        $p = Product::where('supplier_id', $shop->id)->firstOrFail();
        \Illuminate\Support\Facades\DB::table('products')->where('id', $p->id)->update(['listing_type' => 'sale']); // restock is for items sold
        $p = $p->fresh();
        $this->actingAs($rico)->withSession(['mode' => 'supplier'])->post(route('supplier.inventory.restock', $p), ['qty' => 3])->assertRedirect();
        $a = AuditLog::where('supplier_id', $rico->shop()->id)->latest('id')->firstOrFail();
        $this->assertSame($rico->id, $a->actor_id);
        $this->assertStringContainsString('Inventory Keeper', $a->role_label);
        $this->assertSame('inventory', $a->category);
        $this->assertSame($p->name, $a->ref);
    }

    public function test_automatic_events_appear_in_the_store_history(): void
    {
        $this->accepted('PR-0003');
        config(['services.paymongo.secret_key' => '', 'services.paymongo.fake' => true]);
        $r = \App\Models\RentalRequest::where('code', 'PR-0003')->firstOrFail();
        $amt = \App\Domain\Finance::suggestedNext($r)['amount'];
        $this->as('jose@demo.ph', 'customer')->post('/rentals/PR-0003/payments', ['type' => 'down', 'amount' => $amt, 'method' => 'online']);
        $p = \App\Models\Payment::where('gateway', 'paymongo')->latest('id')->firstOrFail();
        $this->post('/practice-checkout/'.$p->gateway_ref, ['action' => 'pay', 'method' => 'gcash']);

        $a = AuditLog::where('supplier_id', $r->supplier_id)->where('category', 'payments')->latest('id')->firstOrFail();
        $this->assertNull($a->actor_id);
        $this->assertSame(['payment', 'PartyRent (automatic)', 'PR-0003'], [$a->type, $a->role_label, $a->ref]);
        $owner = Supplier::find($r->supplier_id)->owner;
        $this->actingAs($owner)->withSession(['mode' => 'supplier'])->get(route('supplier.activity', ['who' => 'auto']))->assertOk()->assertSee('PartyRent (automatic)')->assertSee('PR-0003');
    }

    public function test_records_cannot_be_changed_or_deleted(): void
    {
        $a = AuditLog::create(['actor_id' => null, 'role' => 'system', 'action' => 'Test line', 'details' => 'x', 'supplier_id' => Supplier::firstOrFail()->id, 'created_at' => now()]);
        $this->assertFalse($a->update(['action' => 'Changed']));
        $this->assertFalse($a->delete());
        $this->assertSame('Test line', $a->fresh()->action);
    }

    public function test_filters_and_another_shop_cannot_see_it(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $k = Package::where('supplier_id', $juan->supplier->id)->where('is_draft', false)->firstOrFail();
        $this->as('juan@demo.ph')->post(route('supplier.packages.active', $k));

        $this->as('juan@demo.ph')->get(route('supplier.activity', ['cat' => 'packages']))->assertSee($k->name);
        $this->as('juan@demo.ph')->get(route('supplier.activity', ['cat' => 'payouts']))->assertDontSee('turned package');
        $this->as('juan@demo.ph')->get(route('supplier.activity', ['q' => 'no-such-thing-xyz']))->assertSee('Nothing matches these filters');
        $this->as('ana@demo.ph')->get(route('supplier.activity'))->assertOk()->assertDontSee($k->name.'</span>', false);
    }
}
