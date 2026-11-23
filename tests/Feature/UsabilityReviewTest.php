<?php

namespace Tests\Feature;

use App\Domain\Finance;
use App\Domain\NextStep;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Usability review 2026-11-24: customer- and supplier-facing text agrees with what the system actually does. */
class UsabilityReviewTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paymongo.secret_key' => '', 'services.paymongo.fake' => true]);
    }

    public function test_guests_see_log_in_and_how_renting_works(): void
    {
        $this->get('/')->assertOk()->assertSee('Log in')->assertSee('How renting works')->assertSee('Nothing is charged yet.');
    }

    public function test_the_first_payment_is_due_within_24_hours_everywhere(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $this->assertStringContainsString('within 24 hours of acceptance', Finance::planSummary($juan->supplier, 'standard'));
        $this->assertStringContainsString('within 24 hours of acceptance', Finance::planSummary($juan->supplier, 'full'));
        $this->assertStringNotContainsString('days of acceptance', Finance::planSummary($juan->supplier, 'standard'));

        $r = RentalRequest::where('code', 'PR-0003')->firstOrFail();
        $r->update(['status' => 'accepted', 'accepted_at' => today_iso()]);
        $first = Finance::schedule($r->fresh())[0];
        $this->assertLessThanOrEqual(add_days(today_iso(), 1), $first['due'], 'the reserving payment is never shown due later than the 24-hour window');
    }

    public function test_accepting_says_the_items_are_not_reserved_yet(): void
    {
        $r = RentalRequest::where('supplier_id', User::where('email', 'juan@demo.ph')->value('id') ? User::where('email', 'juan@demo.ph')->first()->supplier->id : 0)->where('status', 'pending')->firstOrFail();
        $this->actingAs(User::where('email', 'juan@demo.ph')->first())->withSession(['mode' => 'supplier'])
            ->post(route('supplier.requests.accept', $r))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'accepted') && str_contains($m, 'reserved once that payment comes in') && ! str_contains($m, 'set aside'));
    }

    public function test_after_the_deposit_is_settled_only_completing_is_asked(): void
    {
        $r = RentalRequest::where('code', 'PR-0003')->firstOrFail();
        $r->update(['status' => 'inspection', 'accepted_at' => today_iso(), 'inspection' => ['settlement' => 'settled', 'items' => []]]);
        $r = $r->fresh();
        $this->assertLessThanOrEqual(0, $r->fin()['depositHeld']);
        $s = NextStep::rental($r, 'supplier');
        $this->assertStringContainsString('Complete the rental', $s['text']);
        $this->assertStringNotContainsString('settle the deposit', $s['text']);
        $this->actingAs($r->supplier->owner)->withSession(['mode' => 'supplier'])->get(route('supplier.requests.show', $r))
            ->assertOk()->assertSee('Complete rental')->assertDontSee('data-modal="settleModal"', false);
    }

    public function test_shop_payment_settings_offer_cash_only_besides_online(): void
    {
        $this->actingAs(User::where('email', 'juan@demo.ph')->first())->withSession(['mode' => 'supplier'])
            ->get(route('supplier.shop', ['tab' => 'plans']))->assertOk()
            ->assertSee('Customers pay online through PartyRent')->assertDontSee('Online Bank Transfer')->assertSee('24 hours');
    }
}
