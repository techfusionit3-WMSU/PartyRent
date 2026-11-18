<?php

namespace Tests\Feature;

use App\Domain\Finance;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Supplier Shop Profile & Settings (reference SP.shop / Services.saveShopSettings): one section per tab. */
class ShopSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function save(string $section, array $data)
    {
        return $this->post(route('supplier.shop.update'), ['section' => $section] + $data);
    }

    public function test_each_tab_saves_its_section(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $s = $juan->supplier;
        $this->actingAs($juan)->withSession(['mode' => 'supplier']);
        foreach (['profile', 'delivery', 'hours', 'plans', 'services', 'damage', 'policies'] as $t) {
            $this->get(route('supplier.shop', ['tab' => $t]))->assertOk();
        }

        // delivery zones
        $this->save('delivery', ['fulfillment' => 'both', 'delivery_fee' => 600, 'zones' => [['name' => 'Quezon City', 'fee' => 250], ['name' => '', 'fee' => '']]])->assertSessionHas('success');
        $s->refresh();
        $this->assertSame('Quezon City', $s->delivery_zones[0]['name']);
        $this->assertCount(1, $s->delivery_zones);
        $this->assertEquals(600, $s->delivery_fee);
        $this->save('delivery', ['fulfillment' => 'both', 'delivery_fee' => 0, 'zones' => [['name' => 'Q', 'fee' => 10]]])->assertSessionHas('error');

        // rental hours
        $this->save('hours', ['open' => '22:00', 'close' => '08:00', 'prepHours' => 1, 'recoveryHours' => 1])->assertSessionHas('error');
        $this->save('hours', ['open' => '07:00', 'close' => '21:00', 'prepHours' => 2, 'recoveryHours' => 0.5])->assertSessionHas('success');
        $this->assertSame(['07:00', '21:00', 2.0, 0.5], [$s->fresh()->time_settings['open'], $s->fresh()->time_settings['close'], (float) $s->fresh()->time_settings['prepHours'], (float) $s->fresh()->time_settings['recoveryHours']]);

        // payment plans: a 0% down payment is refused (it is what reserves the booking); full only is fine
        $plans = ['standard' => ['enabled' => 1, 'downPct' => 0, 'downDays' => 14]];
        $this->save('plans', ['methods' => ['bank'], 'plans' => $plans, 'depositType' => 'fixed', 'depositValue' => 500])->assertSessionHas('error');
        $this->save('plans', ['methods' => [], 'plans' => ['full' => ['enabled' => 1, 'days' => 7]], 'depositType' => 'fixed', 'depositValue' => 500])->assertSessionHas('error');
        $this->save('plans', ['methods' => ['bank'], 'plans' => ['full' => ['enabled' => 1, 'days' => 7]], 'depositType' => 'percent', 'depositValue' => 10])->assertSessionHas('success');
        $this->assertSame(['full'], Finance::plansOf($s->fresh()));
        $this->assertSame('percent', $s->fresh()->policy['depositType']);

        // services
        $this->save('services', ['services' => [['name' => 'Setup and styling', 'amount' => 1500, 'per' => 'location', 'required' => 1, 'description' => 'Two crew']]])->assertSessionHas('success');
        $this->assertSame(['location', true], [$s->fresh()->services[0]['per'], $s->fresh()->services[0]['required']]);
        $this->save('services', ['services' => [['name' => 'X', 'amount' => 10]]])->assertSessionHas('error');

        // damage policy with a category rule
        $cat = Category::where('active', true)->firstOrFail();
        $d = ['rep_on' => 1, 'rep_pct' => 40, 'non_on' => 1, 'non_pct' => 75, 'lost_on' => 1, 'lost_brand_new' => 100, 'lost_good_as_new' => 90, 'lost_used' => 60];
        $this->save('damage', ['d' => $d, 'addCategory' => $cat->id])->assertSessionHas('success');
        $this->assertArrayHasKey($cat->id, $s->fresh()->damage_policy['categories']);
        $this->assertEquals(75, $s->fresh()->damage_policy['nonRepairable']['pct']);
        $this->save('damage', ['d' => ['rep_pct' => 140] + $d])->assertSessionHas('error');

        // policies
        $this->save('policies', ['cancelDays' => 4, 'cancellation' => 'Free up to 4 days before.', 'returns' => 'Return clean.', 'conditions' => 'Renter pays for damage.'])->assertSessionHas('success');
        $this->assertSame(4, $s->fresh()->policy['cancelDays']);
    }

    public function test_staff_without_shop_permission_cannot_save(): void
    {
        $rico = User::where('email', 'inventory@elegantoccasion.ph')->firstOrFail();
        $this->actingAs($rico)->withSession(['mode' => 'supplier'])->post(route('supplier.shop.update'), ['section' => 'policies', 'cancellation' => 'x', 'returns' => 'x', 'conditions' => 'x'])->assertRedirect();
        $this->assertNotSame('x', $rico->shop()->fresh()->policy['cancellation'] ?? null);
    }
}
