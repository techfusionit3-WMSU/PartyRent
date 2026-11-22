<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\RequestEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feedback 2026-11-22: every change a shop's people make records who and when; the owner can open organized,
 * printable transaction records and an activity log.
 */
class StaffActivityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_staff_actions_are_logged_with_who_and_when(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $r = RentalRequest::where('supplier_id', $juan->supplier->id)->whereIn('status', \App\Domain\Finance::ACTIVE)->firstOrFail();
        $this->actingAs($juan)->withSession(['mode' => 'supplier']);

        $this->from(route('supplier.requests.show', $r))->post(route('supplier.requests.notes', $r), ['notes' => 'Bring extra cables'])->assertSessionHas('success');
        $log = AuditLog::where('supplier_id', $juan->supplier->id)->latest('id')->firstOrFail();
        $this->assertSame('Updated the internal notes', $log->action);
        $this->assertSame($juan->id, $log->actor_id);
        $this->assertSame($r->code, $log->ref);
        $this->assertTrue(RequestEvent::where('rental_request_id', $r->id)->where('status', 'activity')->where('user_id', $juan->id)->where('note', 'Updated the internal notes')->exists());

        // the shop sees it on the rental with the name; the customer does not see staff actions
        $this->get(route('supplier.requests.show', $r))->assertOk()->assertSee('Updated the internal notes')->assertSee($juan->name);
        $this->actingAs($r->customer)->withSession(['mode' => 'customer'])->get(route('rentals.show', $r))->assertOk()->assertDontSee('Updated the internal notes');

        // a refused action is not logged
        $before = AuditLog::count();
        $pending = Payment::whereHas('rentalRequest', fn ($q) => $q->where('supplier_id', $juan->supplier->id))->where('status', 'pending')->whereNotIn('method', ['cash', 'cod'])->first();
        if ($pending) {
            $this->actingAs($juan)->withSession(['mode' => 'supplier'])->post(route('supplier.payments.verify', $pending))->assertSessionHas('error');
            $this->assertSame($before, AuditLog::count());
        }
    }

    public function test_owner_opens_printable_records_and_activity_log(): void
    {
        $juan = User::where('email', 'juan@demo.ph')->firstOrFail();
        $this->actingAs($juan)->withSession(['mode' => 'supplier']);
        $r = RentalRequest::where('supplier_id', $juan->supplier->id)->orderBy('start_date')->firstOrFail();
        $this->post(route('supplier.requests.notes', $r), ['notes' => 'x']);

        $this->get(route('supplier.records', ['range' => 'custom', 'from' => '2020-01-01', 'to' => '2030-12-31']))
            ->assertOk()->assertSee('Transaction Records')->assertSee($r->code)->assertSee('Print')->assertSee('Processed by');
        $this->get(route('supplier.records', ['range' => 'custom', 'from' => '2020-01-01', 'to' => '2030-12-31', 'csv' => 1]))
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->get(route('supplier.activity'))->assertOk()->assertSee('Updated the internal notes')->assertSee($juan->name);

        // the activity log is the owner's; a front-desk employee cannot open it
        $desk = User::where('email', 'desk@elegantoccasion.ph')->firstOrFail();
        $this->actingAs($desk)->withSession(['mode' => 'supplier'])->get(route('supplier.activity'))->assertRedirect();
    }
}
