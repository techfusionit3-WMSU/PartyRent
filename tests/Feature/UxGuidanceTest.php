<?php

namespace Tests\Feature;

use App\Domain\NextStep;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Guidance: "what happens next", "Needs you", first-time guides, "Working as", phone-first crew page. */
class UxGuidanceTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_next_step_says_whose_turn_it_is(): void
    {
        $r = RentalRequest::where('status', 'pending')->with(['supplier', 'customer'])->firstOrFail();
        $this->assertSame('them', NextStep::rental($r, 'customer')['turn']);
        $shop = NextStep::rental($r, 'shop');
        $this->assertSame('you', $shop['turn']);
        $this->assertStringContainsString('accept or decline', $shop['text']);

        $this->actingAs($r->customer)->withSession(['mode' => 'customer'])->get(route('rentals.show', $r))
            ->assertOk()->assertSee('What happens next')->assertSee('Waiting for '.$r->supplier->name, false);
    }

    public function test_front_desk_sees_needs_you_with_one_button_each(): void
    {
        $leah = $this->user('desk@elegantoccasion.ph');
        $pending = RentalRequest::where('supplier_id', $leah->shop()->id)->where('status', 'pending')->count();
        $res = $this->actingAs($leah)->withSession(['mode' => 'supplier'])->get('/supplier/desk')->assertOk()->assertSee('Needs you');
        if ($pending) {
            $res->assertSee('New request');
        }
        // first visit: the welcome card, gone once dismissed
        $res->assertSee('Welcome to')->assertSee("You're working as Front Desk");
        $this->post('/dismiss', ['key' => 'welcome.desk.'.$leah->shop()->id])->assertRedirect();
        $this->get('/supplier/desk')->assertDontSee('Welcome to');
        $this->post('/dismiss', ['key' => 'anything'])->assertStatus(422);
    }

    public function test_working_as_shows_module_and_shop(): void
    {
        $this->actingAs($this->user('staff@elegantoccasion.ph'))->withSession(['mode' => 'supplier'])
            ->get('/supplier/crew')->assertOk()->assertSee('Working as Field Crew')->assertSee('Needs you today');
    }

    public function test_new_shop_gets_a_setup_checklist_until_hidden(): void
    {
        $owner = $this->user('liza@demo.ph');
        $owner->supplier->forceFill(['payout_method' => null])->save();
        $this->actingAs($owner)->withSession(['mode' => 'supplier'])->get('/supplier')->assertOk()
            ->assertSee('Get your shop ready')->assertSee('Set your payout account');
        $this->post('/dismiss', ['key' => 'guide.shop']);
        $this->get('/supplier')->assertDontSee('Get your shop ready');
    }
}
