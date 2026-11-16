<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** v4 Payment Monitoring: an individual shop verifies its own payments; other shops cannot. */
class SupplierPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_shop_owner_verifies_and_rejects_own_payments(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $pending = Payment::whereHas('rentalRequest', fn ($q) => $q->where('supplier_id', $juan->supplier->id))->where('status', 'pending')->firstOrFail();

        // another shop's owner cannot touch it
        $ana = User::where('email', 'ana@demo.ph')->firstOrFail();
        $this->actingAs($ana)->withSession(['mode' => 'supplier'])->post(route('supplier.payments.verify', $pending))->assertNotFound();

        $this->actingAs($juan)->withSession(['mode' => 'supplier']);
        $this->get(route('supplier.payments'))->assertOk()->assertSee('Verification queue')->assertSee($pending->code)->assertSee('Balance check');
        $this->get(route('supplier.payments', ['tab' => 'balances']))->assertOk()->assertSee('Next deadline');
        $this->post(route('supplier.payments.reject', $pending), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('supplier.payments.verify', $pending))->assertSessionHas('success');
        $pending->refresh();
        $this->assertSame('verified', $pending->status);
        $this->assertNotNull($pending->receipt_no);
        $this->assertSame($juan->id, $pending->verified_by);
    }
}
