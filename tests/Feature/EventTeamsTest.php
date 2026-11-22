<?php

namespace Tests\Feature;

use App\Domain\EventTeams;
use App\Models\EventBooking;
use App\Models\EventTeam;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teams (2026-11-22): how many events an event company runs at the same time comes from its teams. A team holds
 * setup + event + teardown hours; confirmed bookings get a free team per part; a team cannot be double-booked.
 */
class EventTeamsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    private string $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Supplier::where('name', 'Grand Fiesta Events Co.')->firstOrFail(); // Team A + Team B, setup 4 h, teardown 2 h
        $this->day = today()->addDays(50)->toDateString();
    }

    private function held(string $type, string $start, string $end): EventBooking
    {
        $b = EventBooking::create(['code' => 'EVT-'.random_int(20000, 99999), 'supplier_id' => $this->s->id, 'customer_id' => User::where('email', 'maria@demo.ph')->value('id'),
            'status' => 'confirmed', 'event_type' => $type, 'event_date' => $this->day, 'guests' => 60, 'venue' => 'Hall']);
        $b->parts()->create(['name' => 'Event', 'date' => $this->day, 'start_time' => $start, 'end_time' => $end, 'sort' => 0]);
        EventTeams::assign($b);

        return $b->fresh('parts');
    }

    private function ask(string $start, string $end)
    {
        return $this->actingAs(User::where('email', 'jose@demo.ph')->firstOrFail())->withSession(['mode' => 'customer'])->post(route('events.store', $this->s), [
            'event_type' => 'Corporate Party', 'guests' => 100, 'venue' => 'Ballroom, Makati', 'parts' => [[], ['date' => $this->day, 'start' => $start, 'end' => $end]],
        ]);
    }

    public function test_two_teams_two_events_at_once_and_mornings_stay_free(): void
    {
        $this->assertSame(2, EventTeams::teams($this->s)->count());
        $a = $this->held('Wedding', '14:00', '18:00');
        $b = $this->held('Debut', '15:00', '20:00');
        $this->assertNotSame($a->parts[0]->event_team_id, $b->parts[0]->event_team_id); // each got its own team
        // both teams busy in the afternoon (setup + teardown included)
        $this->ask('16:00', '19:00')->assertSessionHas('error', fn ($e) => str_contains($e, 'at that time'));
        // early morning: the window 02:00–09:00 does not touch either
        $this->ask('06:00', '07:00')->assertRedirect();
    }

    public function test_assign_and_move_a_part_between_teams(): void
    {
        $a = $this->held('Wedding', '14:00', '18:00');
        $b = $this->held('Debut', '09:00', '10:00'); // 05:00–12:00 window overlaps the wedding's 10:00–20:00
        $teamA = $a->parts[0]->event_team_id;
        $owner = $this->actingAs($this->s->owner)->withSession(['mode' => 'supplier']);
        $owner->post(route('business.bookings.part-team', [$b, $b->parts[0]->id]), ['team' => $teamA])->assertSessionHas('error', fn ($e) => str_contains($e, 'already works another event'));
        $this->post(route('business.bookings.part-team', [$b, $b->parts[0]->id]), ['team' => ''])->assertSessionHas('success');
        $this->assertNull($b->parts[0]->fresh()->event_team_id);
        // the booking page lets the owner set the team; the teams page shows each team's next events
        $this->get(route('business.bookings.show', $a))->assertOk()->assertSee('Set team');
        $this->get(route('business.teams'))->assertOk()->assertSee('Team A')->assertSee($a->code);
        // a team with upcoming events cannot stop working
        $t = EventTeam::find($teamA);
        $this->post(route('business.teams.update', $t), ['name' => 'Team A', 'active' => 0])->assertSessionHas('error');
        $this->post(route('business.teams.store'), ['name' => 'Team C', 'members' => [$this->s->owner_id]])->assertSessionHas('success');
        $this->assertSame(3, EventTeams::teams($this->s)->count());
    }

    public function test_without_teams_the_events_a_day_setting_applies(): void
    {
        EventTeam::where('supplier_id', $this->s->id)->update(['active' => false]);
        $this->held('Wedding', '14:00', '18:00');
        // events a day = 1: even an early morning event is refused
        $this->ask('06:00', '07:00')->assertSessionHas('error', fn ($e) => str_contains($e, 'fully booked'));
        $this->actingAs($this->s->owner)->withSession(['mode' => 'supplier'])->get(route('business.teams'))->assertOk()->assertSee('No teams yet');
    }
}
