<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\CartLine;
use App\Models\CustomerVerification;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Customers verify (government ID + payment account; insurance optional) before renting; admins review. */
class CustomerVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function img(string $name = 'id.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->maria = User::where('email', 'maria@demo.ph')->firstOrFail();
        CustomerVerification::where('user_id', $this->maria->id)->delete(); // a new, unverified customer
        $this->actingAs($this->maria)->withSession(['mode' => 'customer']);
    }

    private function form(array $x = []): array
    {
        return array_merge(['id_type' => 'philsys', 'id_last4' => '1234', 'id_front' => $this->img(), 'pay_provider' => 'GCash', 'pay_account_name' => 'Maria Santos',
            'pay_last4' => '0101', 'pay_proof' => $this->img('proof.png'), 'consent' => '1'], $x);
    }

    public function test_unverified_customer_cannot_check_out(): void
    {
        $s = Supplier::where('name', 'Happy Moments Party Rentals')->firstOrFail();
        CartLine::where('user_id', $this->maria->id)->delete();
        $p = Product::where('supplier_id', $s->id)->where('listing_type', 'rent')->where('status', 'active')->where('package_only', false)->firstOrFail();
        $this->post('/cart', ['type' => 'item', 'ref_id' => $p->id, 'days' => json_encode([add_days(today_iso(), 30) => 1])]);
        $ids = CartLine::where('user_id', $this->maria->id)->pluck('id')->implode(',');
        $this->get(route('checkout', ['ids' => $ids]))->assertOk()->assertSee('Verify your account to rent');
        $res = $this->checkout($s->id, ['contactName' => 'Maria', 'contactPhone' => '0917 555 0101', 'fulfillment' => 'pickup'])->assertStatus(422);
        $this->assertStringContainsString('Verify your account before renting', $res->json('error'));
    }

    public function test_submit_review_and_rent(): void
    {
        $this->get(route('account.verification'))->assertOk()->assertSee('Not Submitted')->assertSee('never asks for your password, PIN or OTP', false);
        // required parts
        $this->post(route('account.verification.submit'), $this->form(['id_front' => null]))->assertSessionHasErrors('id_front');
        $this->post(route('account.verification.submit'), $this->form(['pay_last4' => '12']))->assertSessionHasErrors('pay_last4');
        $this->post(route('account.verification.submit'), $this->form(['id_front' => UploadedFile::fake()->createWithContent('x.exe', 'MZ')]))->assertSessionHasErrors('id_front');
        $this->post(route('account.verification.submit'), $this->form(['insurance' => $this->img('ins.png'), 'insurer' => 'Malayan']))->assertSessionHas('success');
        $v = CustomerVerification::where('user_id', $this->maria->id)->firstOrFail();
        $this->assertSame('pending', $v->status);
        $this->assertSame('submitted', $v->insurance_status);
        Storage::disk('local')->assertExists($v->id_front_path);
        $this->assertStringStartsWith('verifications/'.$this->maria->id.'/', $v->id_front_path);
        $this->assertStringContainsString('being reviewed', (string) \App\Domain\Verifications::blocker($this->maria));
        // can't send again while it is being reviewed
        $this->post(route('account.verification.submit'), $this->form())->assertSessionHas('error');

        // files: the customer and admins only
        $this->get(route('account.verification.file', 'id_front'))->assertOk();
        $other = User::where('email', 'juan@demo.ph')->firstOrFail();
        $admin = User::where('email', 'admin@partyrent.ph')->firstOrFail();
        $this->actingAs($other)->withSession(['mode' => 'customer'])->get(route('admin.verifications.file', [$v, 'id_front']))->assertRedirect();
        $this->get(route('account.verification.file', 'id_front'))->assertNotFound(); // their own route only reaches their own files

        $this->actingAs($admin)->withSession(['mode' => 'admin']);
        $this->get(route('admin.verifications'))->assertOk()->assertSee('Maria Santos');
        $this->get(route('admin.verifications', ['id' => $v->id]))->assertOk()->assertSee('Approve')->assertSee('Ask to send again');
        $this->get(route('admin.verifications.file', [$v, 'pay_proof']))->assertOk();
        // a reason is needed to ask again
        $this->post(route('admin.verifications.decide', $v), ['action' => 'resubmit'])->assertSessionHas('error');
        $this->post(route('admin.verifications.decide', $v), ['action' => 'resubmit', 'reason' => 'The ID photo is blurry'])->assertSessionHas('success');
        $this->assertSame('resubmit', $v->fresh()->status);
        $this->assertTrue(AppNotification::where('user_id', $this->maria->id)->where('title', 'Please send your verification again')->exists());

        // the customer fixes it: older files kept unless replaced
        $this->actingAs($this->maria)->withSession(['mode' => 'customer']);
        $this->get(route('account.verification'))->assertSee('The ID photo is blurry');
        $this->post(route('account.verification.submit'), $this->form(['pay_proof' => null]))->assertSessionHas('success');
        $this->assertSame('pending', $v->fresh()->status);

        $this->actingAs($admin)->withSession(['mode' => 'admin']);
        $this->post(route('admin.verifications.decide', $v), ['action' => 'approve', 'insurance' => 'verify'])->assertSessionHas('success');
        $v->refresh();
        $this->assertSame('approved', $v->status);
        $this->assertSame(['Identity Verified', 'Payment Account Verified', 'Insurance Verified'], array_column($v->trustBadges(), 0));
        $this->assertNull(\App\Domain\Verifications::blocker($this->maria));
    }

    public function test_rejected_shows_the_reason_and_can_be_sent_again(): void
    {
        $this->post(route('account.verification.submit'), $this->form())->assertSessionHas('success');
        $v = CustomerVerification::where('user_id', $this->maria->id)->firstOrFail();
        $admin = User::where('email', 'admin@partyrent.ph')->firstOrFail();
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->post(route('admin.verifications.decide', $v), ['action' => 'reject', 'reason' => 'The names do not match'])->assertSessionHas('success');
        $this->assertSame('rejected', $v->fresh()->status);
        $this->actingAs($this->maria)->withSession(['mode' => 'customer'])->get(route('account.verification'))->assertSee('Rejected')->assertSee('The names do not match')->assertSee('Send again');
    }

    public function test_shop_sees_trust_badges_on_the_request(): void
    {
        $r = \App\Models\RentalRequest::whereHas('customer', fn ($q) => $q->where('email', 'maria@demo.ph'))->firstOrFail();
        $owner = $r->supplier->owner;
        $this->actingAs($owner)->withSession(['mode' => 'supplier'])->get(route('supplier.requests.show', $r))->assertOk()->assertSee('Not verified yet');
        CustomerVerification::create(['user_id' => $this->maria->id, 'status' => 'approved', 'id_verified_at' => now(), 'pay_verified_at' => now(), 'insurance_status' => 'submitted']);
        $this->get(route('supplier.requests.show', $r))->assertSee('Identity Verified')->assertSee('Payment Account Verified')->assertSee('Insurance Submitted');
    }
}
