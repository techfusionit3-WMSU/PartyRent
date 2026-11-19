<?php

namespace Tests\Feature;

use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Clarity and ease of use: language switch, term tips, step-by-step forms with drafts, accessibility basics. */
class UxClarityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_switch_to_filipino_and_back(): void
    {
        $this->get('/')->assertOk()->assertSee('lang="en"', false)->assertSee('Skip to content');
        $this->from('/')->post('/lang', ['locale' => 'fil'])->assertRedirect('/')->assertCookie('pr_lang');
        $this->get('/')->assertOk()->assertSee('lang="fil"', false)->assertSee('Mga Supplier')->assertSee('Lumaktaw sa nilalaman');
        $this->post('/lang', ['locale' => 'xx']);
        $this->get('/')->assertSee('lang="en"', false);
    }

    public function test_module_menu_and_next_step_follow_the_language(): void
    {
        $leah = User::where('email', 'desk@elegantoccasion.ph')->firstOrFail();
        $this->actingAs($leah)->withSession(['mode' => 'supplier', 'locale' => 'fil'])
            ->get('/supplier/desk')->assertOk()->assertSee('Kailangan ka')->assertSee('Nagtatrabaho bilang Front Desk');
        $r = RentalRequest::where('status', 'pending')->firstOrFail();
        $this->actingAs($r->customer)->withSession(['mode' => 'customer', 'locale' => 'fil'])
            ->get(route('rentals.show', $r))->assertOk()->assertSee('Ano ang susunod')->assertSee('Hinihintay ang');
    }

    public function test_product_form_is_step_by_step_with_a_draft(): void
    {
        $this->actingAs(User::where('email', 'juan@demo.ph')->firstOrFail())->withSession(['mode' => 'supplier'])
            ->get('/supplier/products/new')->assertOk()
            ->assertSee('data-wizard', false)->assertSee('data-draft="product-new"', false)
            ->assertSee('data-wiz-step="Item"', false)->assertSee('data-wiz-step="Price"', false)->assertSee('data-wiz-submit', false)
            ->assertSee('class="term"', false);
    }

    public function test_start_selling_keeps_a_draft_and_shows_progress(): void
    {
        $this->actingAs(User::where('email', 'maria@demo.ph')->firstOrFail())->withSession(['mode' => 'customer'])
            ->get('/start-selling')->assertOk()->assertSee('data-draft="start-selling"', false)->assertSee('id="wizBar"', false);
    }

    public function test_checkout_terms_have_plain_meanings(): void
    {
        $this->assertStringContainsString('data-term=', (string) term('down_payment'));
        $this->assertSame('', (string) term('nope'));
        app()->setLocale('fil');
        $this->assertStringContainsString('Ano ang Down payment?', (string) term('down_payment'));
    }
}
