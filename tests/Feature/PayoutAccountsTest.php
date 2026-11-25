<?php

namespace Tests\Feature;

use App\Models\PayoutAccount;
use App\Models\PayoutBatch;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Several payout accounts per shop: per-provider fields, encrypted + masked numbers, default, no card numbers. */
class PayoutAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Supplier::where('name', 'Happy Moments Party Rentals')->firstOrFail();
        $this->actingAs($this->s->owner)->withSession(['mode' => 'supplier']);
    }

    public function test_the_existing_account_becomes_the_first_default_one_and_is_masked(): void
    {
        $this->assertTrue($this->s->hasPayoutAccount());
        $digits = preg_replace('/\D/', '', $this->s->payout_account_no);
        $this->get(route('supplier.payouts'))->assertOk()->assertSee('Payout accounts')->assertSee('•••• '.substr($digits, -4))
            ->assertDontSee($this->s->payout_account_no)->assertSee('How payouts work')->assertSee('Customer payment date')->assertSee('Processing');
        $a = $this->s->payoutAccounts()->firstOrFail();
        $this->assertTrue($a->is_default);
        $this->assertSame($digits, $a->account_number);
        // encrypted in the database
        $this->assertStringNotContainsString($digits, (string) DB::table('payout_accounts')->where('id', $a->id)->value('account_number'));
    }

    public function test_provider_fields_are_checked(): void
    {
        $post = fn ($x) => $this->post(route('supplier.payouts.accounts.store'), $x);
        $post(['category' => 'ewallet', 'provider' => 'gcash', 'account_name' => 'Juan Dela Cruz', 'account_number' => '12345'])->assertSessionHasErrors('account_number');
        $post(['category' => 'bank', 'provider' => 'bpi', 'account_name' => 'Juan Dela Cruz', 'account_number' => '123456789', 'account_type' => 'savings'])->assertSessionHasErrors('account_number');
        $post(['category' => 'bank', 'provider' => 'other_bank', 'account_name' => 'Juan Dela Cruz', 'account_number' => '123456789', 'account_type' => 'savings'])->assertSessionHasErrors('provider_name');
        $post(['category' => 'bank', 'provider' => 'bdo', 'account_name' => 'Juan Dela Cruz', 'account_number' => '123456789012'])->assertSessionHasErrors('account_type');
        $post(['category' => 'ewallet', 'provider' => 'gcash', 'account_name' => 'J4n', 'account_number' => '09171234567'])->assertSessionHasErrors('account_name');
        // debit cards: never a card number here
        $post(['category' => 'card', 'provider' => 'card', 'account_name' => 'Juan', 'account_number' => '4111111111111111', 'cvv' => '123'])->assertSessionHasErrors('category');
        $this->assertSame(0, PayoutAccount::where('category', 'card')->count());
    }

    public function test_add_several_accounts_and_choose_where_payouts_go(): void
    {
        $this->s->ensurePayoutAccounts();
        $this->post(route('supplier.payouts.accounts.store'), ['category' => 'ewallet', 'provider' => 'maya', 'account_name' => 'Juan Dela Cruz', 'account_number' => '0918 765 4321'])->assertSessionHas('success');
        $this->post(route('supplier.payouts.accounts.store'), ['category' => 'bank', 'provider' => 'other_bank', 'provider_name' => 'Metrobank', 'account_name' => 'Juan Dela Cruz', 'account_number' => '123-456-7890-12', 'account_type' => 'checking', 'branch' => 'Pilar St.'])->assertSessionHas('success');
        // the same account twice is refused
        $this->post(route('supplier.payouts.accounts.store'), ['category' => 'ewallet', 'provider' => 'maya', 'account_name' => 'Juan Dela Cruz', 'account_number' => '09187654321'])->assertSessionHasErrors('account_number');
        $this->assertSame(3, $this->s->payoutAccounts()->count());
        $maya = PayoutAccount::where('provider', 'maya')->firstOrFail();
        $this->assertFalse($maya->is_default);
        $this->put(route('supplier.payouts.accounts.default', $maya))->assertSessionHas('success');
        $s = $this->s->fresh();
        $this->assertSame('maya', $s->payout_method);
        $this->assertSame('Maya · Juan Dela Cruz · •••• 4321', $s->payoutAccountLabel());
        $this->assertSame('PH_PAYMAYA', \App\Domain\XenditPayouts::channel($s));
        // the default can't be removed while others exist; another one can
        $this->delete(route('supplier.payouts.accounts.destroy', $maya))->assertSessionHas('error');
        $bank = PayoutAccount::where('provider_name', 'Metrobank')->firstOrFail();
        $this->assertSame(['accountType' => 'checking', 'branch' => 'Pilar St.'], $bank->details);
        $this->delete(route('supplier.payouts.accounts.destroy', $bank))->assertSessionHas('success');
        $this->assertSame(2, $this->s->payoutAccounts()->count());
    }

    public function test_another_shop_cannot_touch_these_accounts(): void
    {
        $a = $this->s->ensurePayoutAccounts()->first();
        $other = Supplier::where('name', 'Celebration Hub Rentals')->firstOrFail();
        $this->actingAs($other->owner)->withSession(['mode' => 'supplier']);
        $this->delete(route('supplier.payouts.accounts.destroy', $a))->assertNotFound();
        $this->put(route('supplier.payouts.accounts.default', $a))->assertNotFound();
    }

    public function test_payout_statuses_shown_to_the_shop(): void
    {
        $b = new PayoutBatch(['status' => 'pending']);
        $this->assertSame('Pending', $b->payoutStatus()[0]);
        $b->failure = 'Invalid account';
        $this->assertSame('Failed', $b->payoutStatus()[0]);
        $this->assertSame('Processing', (new PayoutBatch(['status' => 'sending']))->payoutStatus()[0]);
        $this->assertSame('Completed', (new PayoutBatch(['status' => 'paid']))->payoutStatus()[0]);
    }
}
