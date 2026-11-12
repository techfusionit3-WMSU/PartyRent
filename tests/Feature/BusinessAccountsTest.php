<?php

namespace Tests\Feature;

use App\Models\RentalRequest;
use App\Models\ShopStaff;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Personal vs Business rental partners, the admin document check, and shop staff roles. */
class BusinessAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private const MARIKINA = ['regionCode' => '130000000', 'provinceCode' => '137000000', 'cityCode' => '137402000'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function application(array $over = []): array
    {
        return array_merge([
            'name' => 'Garcia Events Co.', 'description' => 'Weddings and debuts: tents, chairs, lights and styling in Marikina.', 'logo' => '💍', 'contact_person' => 'Jose Garcia',
            'phone' => '0923 555 0707', 'email' => 'jose@demo.ph', 'hours' => 'Daily 8–6',
            'location_type' => 'shop', 'shop_addr' => self::MARIKINA + ['barangay' => 'Marikina Heights', 'street' => '7 Sampaguita St.'],
            'lat' => 14.6520, 'lng' => 121.1100, 'fulfillment' => 'both',
            'delivery' => ['mode' => 'zones', 'zones' => [['regionCode' => '130000000', 'provinceCode' => '137000000', 'cityCode' => '', 'fee' => 300]]],
            'methods' => ['cod'], 'arrangements' => ['full'], 'reservationFee' => 0, 'reservationCredited' => '1',
            'downType' => 'percent', 'downValue' => 0, 'installments' => 2, 'depositType' => 'fixed', 'depositValue' => 500, 'cancelDays' => 2,
            'cancellation' => 'Free up to 2 days before.', 'returns' => 'Return clean.', 'conditions' => 'Renter pays for damage.',
        ], $over);
    }

    /** An approved, verified business owned by Juan (a demo supplier). */
    private function business(): Supplier
    {
        $s = $this->user('juan@demo.ph')->supplier;
        $s->update(['account_type' => 'business', 'biz_reg_type' => 'dti', 'biz_reg_no' => 'DTI 1234567', 'biz_tin' => '123-456-789-000', 'biz_status' => 'verified', 'biz_verified_at' => now()]);

        return $s->fresh();
    }

    public function test_business_application_needs_documents_and_approval_verifies_it(): void
    {
        $this->actingAs($this->user('jose@demo.ph'))->withSession(['mode' => 'customer']);
        $this->get('/start-selling')->assertOk()->assertSee('Account type')->assertSee('Business permit or registration certificate', false);

        $this->post('/start-selling', $this->application(['account_type' => 'business']))
            ->assertSessionHasErrors(['biz_reg_type', 'biz_reg_no', 'biz_tin', 'biz_doc']);

        $this->post('/start-selling', $this->application(['account_type' => 'business', 'biz_reg_type' => 'dti', 'biz_reg_no' => 'DTI 7654321', 'biz_tin' => '123-456-789-000',
            'biz_doc' => UploadedFile::fake()->create('permit.pdf', 200, 'application/pdf')]))->assertSessionHas('success');
        $s = Supplier::where('name', 'Garcia Events Co.')->firstOrFail();
        $this->assertSame('business', $s->account_type);
        $this->assertSame('pending', $s->biz_status);
        Storage::disk('local')->assertExists($s->biz_doc_path);

        $admin = $this->user('admin@partyrent.ph');
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->get('/admin/applications')->assertOk()->assertSee('View permit')->assertSee('DTI 7654321');
        $this->get(route('admin.business.doc', $s))->assertOk();
        $this->post(route('admin.applications.review', $s), ['decision' => 'approve'])->assertSessionHas('success');
        $this->assertTrue($s->fresh()->isVerifiedBusiness());
        $this->get(route('suppliers.show', $s))->assertSee('Verified Business');
    }

    public function test_personal_is_the_default_and_cannot_add_staff(): void
    {
        $this->actingAs($this->user('jose@demo.ph'))->withSession(['mode' => 'customer']);
        $this->post('/start-selling', $this->application())->assertSessionHas('success');
        $this->assertSame('personal', Supplier::where('name', 'Garcia Events Co.')->value('account_type'));

        $juan = $this->user('juan@demo.ph');
        $this->assertFalse($juan->supplier->isBusiness());
        $this->actingAs($juan)->withSession(['mode' => 'supplier'])->get('/supplier/staff')->assertRedirect(route('supplier.shop'));
        $this->get('/supplier/shop')->assertOk()->assertSee('Upgrade to Business')->assertDontSee('Staff &amp; Roles');
    }

    public function test_personal_shop_upgrades_after_admin_check(): void
    {
        $juan = $this->user('juan@demo.ph');
        $s = $juan->supplier;
        $this->actingAs($juan)->withSession(['mode' => 'supplier'])->post('/supplier/shop/business', [
            'biz_reg_type' => 'sec', 'biz_reg_no' => 'CS201912345', 'biz_tin' => '111-222-333',
            'biz_doc' => UploadedFile::fake()->create('permit.jpg', 100, 'image/jpeg'),
        ])->assertSessionHas('success');
        $this->assertSame('pending', $s->fresh()->biz_status);
        $this->get('/supplier/staff')->assertRedirect(route('supplier.shop'));

        $admin = $this->user('admin@partyrent.ph');
        $this->actingAs($admin)->withSession(['mode' => 'admin'])->get('/admin/applications?tab=business')->assertOk()->assertSee($s->name)->assertSee('Verify business');
        $this->post(route('admin.business.review', $s), ['decision' => 'reject'])->assertSessionHas('error'); // reason needed
        $this->post(route('admin.business.review', $s), ['decision' => 'approve'])->assertSessionHas('success');

        $this->actingAs($juan->fresh())->withSession(['mode' => 'supplier'])->get('/supplier/staff')->assertOk()->assertSee('Add a person');
    }

    public function test_owner_adds_staff_and_roles_limit_pages(): void
    {
        $s = $this->business();
        $juan = $this->user('juan@demo.ph');
        $this->actingAs($juan)->withSession(['mode' => 'supplier'])
            ->post('/supplier/staff', ['name' => 'Liza Cruz', 'email' => 'liza@example.com', 'role' => 'cashier'])->assertSessionHas('success');
        $liza = $this->user('liza@example.com');
        Notification::assertSentTo($liza, ResetPassword::class);
        $this->assertSame('cashier', $liza->shopRole());
        $this->assertSame($s->id, $liza->shop()->id);

        // someone with their own shop cannot be staff
        $this->post('/supplier/staff', ['email' => $this->user('ana@demo.ph')->email, 'role' => 'warehouse'])->assertSessionHas('error');

        $pending = RentalRequest::where('supplier_id', $s->id)->where('status', 'pending')->first();

        $this->actingAs($liza->fresh())->withSession(['mode' => 'customer']);
        $this->post('/mode', ['mode' => 'supplier'])->assertRedirect(route('supplier.dashboard'));
        $this->get('/supplier')->assertOk()->assertSee($s->name)->assertDontSee('Staff &amp; Roles');
        $this->get('/supplier/payments')->assertOk();
        $this->get('/supplier/inventory')->assertRedirect(route('supplier.dashboard'));
        $this->put('/supplier/payouts/account', [])->assertRedirect(route('supplier.dashboard'));
        $this->get('/supplier/staff')->assertRedirect(route('supplier.dashboard'));
        if ($pending) {
            $this->get('/supplier/requests/'.$pending->code)->assertOk();
            $this->post('/supplier/requests/'.$pending->code.'/accept')->assertRedirect(route('supplier.dashboard'));
            $this->assertSame('pending', $pending->fresh()->status);
        }

        // owner promotes Liza to warehouse; inventory opens, payments do not
        $m = ShopStaff::where('user_id', $liza->id)->firstOrFail();
        $this->actingAs($juan)->withSession(['mode' => 'supplier'])->put('/supplier/staff/'.$m->id, ['role' => 'warehouse'])->assertSessionHas('success');
        $this->actingAs($liza->fresh())->withSession(['mode' => 'supplier']);
        $this->get('/supplier/inventory')->assertOk();
        $this->get('/supplier/payments')->assertRedirect(route('supplier.dashboard'));

        // removed staff lose access
        $this->actingAs($juan)->withSession(['mode' => 'supplier'])->delete('/supplier/staff/'.$m->id)->assertSessionHas('success');
        $this->assertNull($liza->fresh()->shop());
        $this->actingAs($liza->fresh())->get('/supplier')->assertRedirect(route('start-selling'));
    }

    public function test_staff_see_the_shop_notifications(): void
    {
        $s = $this->business();
        $u = User::create(['name' => 'Rico', 'email' => 'rico@example.com', 'password' => bcrypt('x'), 'roles' => ['customer'], 'status' => 'active', 'email_verified_at' => now()]);
        ShopStaff::create(['supplier_id' => $s->id, 'user_id' => $u->id, 'role' => 'coordinator']);
        \App\Domain\Notifier::notify($s->owner_id, 'supplier', 'New rental request', 'PR-TEST needs a reply.');
        $this->assertTrue(\App\Domain\Notifier::for($u, 'supplier')->contains('title', 'New rental request'));
        $this->assertFalse(\App\Domain\Notifier::for($u, 'customer')->contains('title', 'New rental request'));
    }
}
