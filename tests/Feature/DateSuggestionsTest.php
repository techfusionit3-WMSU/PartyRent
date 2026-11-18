<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** After the post-login date prompt (or the date bar), the home page suggests what is free on those dates. */
class DateSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    public function test_login_prompt_leads_to_items_available_on_the_chosen_dates(): void
    {
        $this->post('/login', ['email' => 'maria@demo.ph', 'password' => 'demo123']);
        $this->get('/')->assertOk()->assertSee('How would you like to browse PartyRent?')->assertSee('#results', false);

        $start = add_days(today_iso(), 10);
        $end = add_days($start, 2);
        $res = $this->get('/?start='.$start.'&end='.$end)->assertOk()->assertSee('Available for your dates')->assertDontSee('Recommended for you');
        $dates = date_range($start, $end);
        $fd = $res->viewData('forDates');
        $this->assertNotEmpty($fd['items']);
        foreach ($fd['items'] as $p) {
            $this->assertGreaterThanOrEqual(1, inv()->minAvailable($p->id, $dates), $p->name.' is suggested but not free');
        }
        foreach ($fd['packages'] as $k) {
            foreach ($dates as $d) {
                $this->assertGreaterThanOrEqual(1, inv()->packageAvailableOn($k->id, $d));
            }
        }
        // the date stays selected for the next visit, so the suggestions stay too
        $this->get('/')->assertOk()->assertSee('Available for your dates');
        // the full list puts free items first
        $list = $res->viewData('list');
        $firstBusy = $list->search(fn ($p) => inv()->minAvailable($p->id, $dates) < 1);
        if ($firstBusy !== false) {
            $this->assertTrue($list->slice($firstBusy)->every(fn ($p) => inv()->minAvailable($p->id, $dates) < 1));
        }
    }

    public function test_no_dates_no_section_and_nothing_free_says_so(): void
    {
        $this->actingAs(User::where('email', 'maria@demo.ph')->first());
        $this->get('/?clear=1')->assertOk()->assertDontSee('Available for your dates');
        // nothing free: the section says so instead of showing busy items
        $d = add_days(today_iso(), 10);
        $html = view('market._for-dates', ['dates' => [$d], 'st' => ['qty' => ''], 'qs' => fn () => '/',
            'forDates' => ['count' => 0, 'items' => collect(), 'packages' => collect()]])->render();
        $this->assertStringContainsString('Nothing is free on all of', $html);
        $this->assertStringContainsString('Change dates', $html);
    }
}
