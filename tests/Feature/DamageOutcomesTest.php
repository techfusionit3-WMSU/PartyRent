<?php

namespace Tests\Feature;

use App\Domain\Damage;
use App\Domain\Rentals;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Individual shops: purchase record → suggested replacement value, release photos, the four damage outcomes,
 * the customer's 48-hour accept / dispute and the PartyRent admin decision.
 */
class DamageOutcomesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        Supplier::query()->update(['account_type' => 'personal']);
        Storage::fake('public');
        Storage::fake('local');
    }

    /** Real 1x1 PNG (GD is not installed, so fake()->image() is unavailable). */
    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    }

    /** A rental of a personal shop waiting for its return inspection; a rental item booked on its own, worth ₱1,000 (minor fee ₱100). */
    private function returnPending(): array
    {
        $itemPid = fn ($r) => collect($r->lines)->first(fn ($l) => ($l['type'] ?? 'item') === 'item' && ! inv()->product($l['ref_id'])?->isSale())['ref_id'] ?? null;
        $r = RentalRequest::whereIn('status', ['ongoing', 'accepted', 'completed'])->get()->first(fn ($r) => $itemPid($r) !== null);
        $r->update(['status' => 'return_pending', 'inspection' => null]);
        $peak = Rentals::peakUnits($r);
        $pid = (int) $itemPid($r);
        Product::whereKey($pid)->update(['replacement_value' => 1000, 'minor_fee' => 100]);
        $r = $this->rebookDamageTerms($r); // as if booked with these fees

        return [$r, $pid, $peak[$pid], $peak];
    }

    private function inspect(RentalRequest $r, array $peak, int $pid, array $row): array
    {
        $items = [];
        foreach ($peak as $id => $q) {
            $items[$id] = ['returned' => $q];
        }
        $items[$pid] = array_merge($items[$pid], $row);
        $this->actingAs($r->supplier->owner);

        return Rentals::recordInspection($r, ['returnDate' => today_iso(), 'items' => $items, 'confirmed' => true]);
    }

    public function test_suggested_value_is_lowered_for_age_and_condition(): void
    {
        $this->assertSame(1000.0, Damage::suggestedValue(1000, today_iso(), 'brand_new'));
        $this->assertSame(637.5, Damage::suggestedValue(1000, now()->subMonths(18)->toDateString(), 'used')); // 85% × 75%
        $this->assertSame(412.5, Damage::suggestedValue(1000, now()->subYears(5)->toDateString(), 'used')); // 55% × 75%
        $this->assertNull(Damage::suggestedValue(null, null, 'used'));
    }

    public function test_product_form_saves_purchase_record_and_private_receipt(): void
    {
        $p = Product::where('listing_type', 'rent')->where('status', 'active')->firstOrFail();
        $owner = $p->supplier->owner;
        $base = ['name' => $p->name, 'category_id' => $p->category_id, 'description' => 'A sturdy item for every party.', 'listing_type' => 'rent', 'total_stock' => $p->total_stock,
            'unit' => 'piece / day', 'condition' => 'used', 'status' => 'active', 'base_price' => 100, 'rental_mode' => 'day'];
        $this->actingAs($owner)->withSession(['mode' => 'supplier'])->get(route('supplier.products.edit', $p))->assertOk()->assertSee('Price you paid')->assertSee('Minor damage (₱ per unit)');

        // value left empty → the suggestion (bought 18 months ago, used: 1,000 × 85% × 75%)
        $this->put(route('supplier.products.update', $p), $base + ['purchase_price' => 1000, 'purchased_on' => now()->subMonths(18)->toDateString(), 'minor_fee' => 80,
            'purchase_receipt' => UploadedFile::fake()->create('receipt.pdf', 50, 'application/pdf')])->assertRedirect();
        $p->refresh();
        $this->assertSame(637.5, (float) $p->replacement_value);
        $this->assertSame(80.0, $p->minor_fee);
        Storage::disk('local')->assertExists($p->purchase_receipt);
        Storage::disk('public')->assertMissing($p->purchase_receipt);
        $this->get(route('supplier.products.receipt', $p))->assertOk();

        // above the price paid needs a reason
        $this->put(route('supplier.products.update', $p), $base + ['purchase_price' => 1000, 'replacement_value' => 1500])->assertSessionHasErrors('value_reason');
        $this->put(route('supplier.products.update', $p), $base + ['purchase_price' => 1000, 'replacement_value' => 1500, 'value_reason' => 'Same chair now costs ₱1,500'])->assertSessionHasNoErrors();
        $this->assertSame(1500.0, (float) $p->fresh()->replacement_value);

        // the customer sees the terms (not the receipt)
        $jose = User::where('email', 'jose@demo.ph')->firstOrFail();
        $this->actingAs($jose)->get(route('products.show', $p))->assertOk()->assertSee('Damage fee')->assertSee('the repair cost')->assertSee('Receipt on file');
        $this->assertNotSame(200, $this->get(route('supplier.products.receipt', $p))->status());
    }

    public function test_release_needs_condition_photos(): void
    {
        $r = RentalRequest::where('status', 'ongoing')->get()->first(fn ($r) => $r->fin()['balance'] <= 0 && Rentals::peakUnits($r) !== []);
        $this->assertNotNull($r, 'demo data has a paid rental that was released');
        $r->update(['status' => 'ready_pickup', 'fulfillment' => 'pickup', 'fulfillment_log' => null]);
        $this->actingAs($r->supplier->owner);
        $res = Rentals::advance($r->fresh(), 'ongoing', ['receivedBy' => 'Jose']);
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('photo', $res['error']);

        $this->withSession(['mode' => 'supplier'])->post(route('supplier.requests.advance', $r), ['to' => 'ongoing', 'receivedBy' => 'Jose', 'releaseDate' => today_iso(), 'releaseQty' => 'all',
            'release_photos' => [$this->png('chairs.png')]])->assertSessionHas('success');
        $this->assertCount(1, $r->fresh()->fulfillment_log['releasePhotos']);
    }

    public function test_four_outcomes_are_priced_differently(): void
    {
        [$r, $pid, $q, $peak] = $this->returnPending();
        $this->assertGreaterThanOrEqual(1, $q);
        Product::whereKey($pid)->update(['total_stock' => 100]);
        // simulate a rental of 6 units: 1 minor, 1 repairable (₱600 quote), 1 not working (normal fault), 1 not working (misuse ₱5,000 → capped ₱1,000), 1 beyond repair, 1 lost
        $lines = $r->lines;
        foreach ($lines as &$l) {
            if (($l['type'] ?? 'item') === 'item' && (int) $l['ref_id'] === $pid) {
                $l['days'] = array_map(fn () => 6, $l['days']);
            }
        }
        unset($l);
        $r->update(['lines' => $lines]);
        $peak = Rentals::peakUnits($r->fresh());
        if (($peak[$pid] ?? 0) !== 6) {
            $this->markTestSkipped('item is only inside a package in this demo rental');
        }
        $as = Damage::assessOutcomes($r->fresh(), [$pid => ['returned' => 5, 'minor' => 1, 'repairable' => 1, 'repairCost' => 600, 'notWorking' => 2, 'misuse' => '1', 'misuseCost' => 5000, 'beyond' => 1, 'description' => 'see photos']]);
        $this->assertTrue($as['ok'], $as['errors'][0] ?? '');
        $it = collect($as['items'])->firstWhere('productId', $pid);
        $this->assertSame(['minor' => 100.0, 'repairable' => 600.0, 'notWorking' => 2000.0, 'beyond' => 1000.0, 'lost' => 1000.0], $it['amounts']);

        // normal fault: not working costs nothing
        $free = Damage::assessOutcomes($r->fresh(), [$pid => ['returned' => 6, 'notWorking' => 1, 'description' => 'speaker stopped working']]);
        $this->assertSame(0.0, collect($free['items'])->firstWhere('productId', $pid)['total']);

        // a repair needs its cost
        $bad = Damage::assessOutcomes($r->fresh(), [$pid => ['returned' => 6, 'repairable' => 1, 'description' => 'cracked leg']]);
        $this->assertStringContainsString('repair cost', $bad['errors'][0]);
    }

    public function test_customer_accepts_then_deposit_can_be_settled(): void
    {
        [$r, $pid, $q, $peak] = $this->returnPending();
        $res = $this->inspect($r, $peak, $pid, ['minor' => 1, 'description' => 'wine stain']);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $r->refresh();
        $this->assertSame('pending', $r->inspection['review']['status']);
        $this->assertSame(100.0, (float) $r->inspection['compensation']);
        $damaged = Product::find($pid)->damaged;

        $settle = Rentals::settleDeposit($r, 'deducted');
        $this->assertFalse($settle['ok']);
        $this->assertStringContainsString('accept or dispute', $settle['error']);

        $this->actingAs($r->customer)->get(route('rentals.show', $r))->assertOk()->assertSee('Please review the charge below')->assertSee('Send my answer');
        $this->post(route('rentals.damage', $r), ['answers' => [$pid => ['action' => 'accept']]])->assertSessionHas('success');
        $this->assertSame('done', $r->fresh()->inspection['review']['status']);
        $this->assertSame($damaged, Product::find($pid)->damaged, 'minor damage does not take the unit out of stock');

        $this->actingAs($r->supplier->owner);
        $this->assertTrue(Rentals::settleDeposit($r->fresh(), 'deducted')['ok']);
    }

    /** Usability review 2026-11-24: the damage charge is easy to find and answer, and nobody is sent to a dead end. */
    public function test_damage_charge_flow_is_clear_for_both_sides(): void
    {
        [$r, $pid, $q, $peak] = $this->returnPending();
        $this->assertTrue($this->inspect($r, $peak, $pid, ['repairable' => 1, 'repairCost' => 150, 'description' => 'cracked leg'])['ok']);
        $r->refresh();

        // customer: a jump button next to "Your turn", and nothing pre-chosen (an answer is a decision)
        $this->actingAs($r->customer)->withSession(['mode' => 'customer'])->get(route('rentals.show', $r))->assertOk()
            ->assertSee('Review the charge')->assertSee('id="damage"', false)->assertDontSee('value="accept" checked', false);
        // shop: told to wait (or drop the charge); completing is not offered yet
        $this->actingAs($r->supplier->owner)->withSession(['mode' => 'supplier'])->get(route('supplier.requests.show', $r))->assertOk()
            ->assertSee('Wait for')->assertSee('Drop the charge')->assertDontSee(route('supplier.requests.complete', $r), false);

        // disputed: both sides are told PartyRent decides; the shop has nothing to press
        $this->actingAs($r->customer)->post(route('rentals.damage', $r), ['answers' => [$pid => ['action' => 'dispute', 'reason' => 'It was loose at delivery.']]])->assertSessionHas('success');
        $r = $r->fresh();
        $this->assertStringContainsString('PartyRent', \App\Domain\NextStep::rental($r, 'customer')['text']);
        $this->assertStringContainsString('PartyRent is deciding', \App\Domain\NextStep::rental($r, 'supplier')['text']);
        $this->actingAs($r->supplier->owner)->withSession(['mode' => 'supplier'])->get(route('supplier.requests.show', $r))->assertOk()
            ->assertSee('disputed the damage charge')->assertDontSee('data-modal="settleModal"', false);
    }

    public function test_dispute_goes_to_admin_who_sets_the_final_amount(): void
    {
        [$r, $pid, $q, $peak] = $this->returnPending();
        $this->assertTrue($this->inspect($r, $peak, $pid, ['repairable' => 1, 'repairCost' => 900, 'description' => 'torn panel'])['ok']);
        $r->refresh();

        $this->actingAs($r->customer)->post(route('rentals.damage', $r), ['answers' => [$pid => ['action' => 'dispute', 'reason' => '']]])->assertSessionHas('error');
        $this->post(route('rentals.damage', $r), ['answers' => [$pid => ['action' => 'dispute', 'reason' => 'The tear is visible in the release photos.']]])->assertSessionHas('success');
        $this->assertSame('disputed', $r->fresh()->inspection['review']['status']);
        $this->actingAs($r->supplier->owner);
        $this->assertStringContainsString('disputed', Rentals::settleDeposit($r->fresh(), 'deducted')['error']);

        $admin = User::whereJsonContains('roles', 'admin')->firstOrFail();
        $this->actingAs($admin)->get(route('admin.disputes'))->assertOk()->assertSee($r->code);
        $this->get(route('admin.disputes.show', $r))->assertOk()->assertSee('The tear is visible')->assertSee('Final amount');
        $this->post(route('admin.disputes.resolve', $r), ['decisions' => [$pid => ['amount' => 2000]]])->assertSessionHas('error');
        $this->post(route('admin.disputes.resolve', $r), ['decisions' => [$pid => ['amount' => 300, 'note' => 'Half of the tear was there before.']]])->assertRedirect(route('admin.disputes'));

        $insp = $r->fresh()->inspection;
        $this->assertSame('done', $insp['review']['status']);
        $this->assertSame(300.0, (float) $insp['compensation']);
        $this->assertSame(900.0, (float) $insp['assessed']);
        $this->actingAs($r->customer)->get(route('rentals.show', $r))->assertOk()->assertSee('PartyRent decision')->assertSee('Half of the tear');
        $this->actingAs($r->supplier->owner);
        $this->assertTrue(Rentals::settleDeposit($r->fresh(), 'deducted')['ok']);
    }

    public function test_no_answer_within_48_hours_counts_as_accepted(): void
    {
        [$r, $pid, $q, $peak] = $this->returnPending();
        $this->assertTrue($this->inspect($r, $peak, $pid, ['returned' => $q - 1, 'description' => 'one not returned'])['ok']);
        $this->assertSame(1000.0, (float) $r->fresh()->inspection['compensation']);
        $this->travel(Damage::REVIEW_HOURS + 1)->hours();
        $this->assertSame(1, Damage::expireReviews());
        $rev = $r->fresh()->inspection['review'];
        $this->assertSame('done', $rev['status']);
        $this->assertTrue($rev['auto']);
    }

    public function test_business_shops_keep_the_percentage_policy(): void
    {
        [$r, $pid, $q, $peak] = $this->returnPending();
        $r->supplier->update(['account_type' => 'business']);
        $res = $this->inspect($r->fresh(), $peak, $pid, ['repairable' => 1, 'description' => 'scratched']);
        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $insp = $r->fresh()->inspection;
        $this->assertArrayNotHasKey('review', $insp);
        $this->assertSame(500.0, (float) $insp['compensation']); // 50% of ₱1,000
    }
}
