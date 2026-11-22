<?php

namespace Tests\Feature;

use App\Models\EventBooking;
use App\Models\EventPackage;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Event parts (2026-11-22): one booking can cover several dates or stages, for any kind of event. Optional parts are
 * chosen by the customer and may cost extra; every part's date must be free; payments are due before the first part;
 * parts are marked done one by one and the booking completes after the last.
 */
class EventPartsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Supplier $s;

    private EventPackage $wedding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = Supplier::where('name', 'Grand Fiesta Events Co.')->firstOrFail();
        $this->wedding = EventPackage::where('name', 'Garden Wedding Package')->firstOrFail();
    }

    private function ask(array $parts, string $email = 'jose@demo.ph')
    {
        return $this->actingAs(User::where('email', $email)->firstOrFail())->withSession(['mode' => 'customer'])->post(route('events.store', $this->s), [
            'event_package_id' => $this->wedding->id, 'event_type' => 'Wedding', 'guests' => 200, 'venue' => 'Garden Pavilion, Tagaytay', 'parts' => $parts,
        ]);
    }

    public function test_package_parts_come_from_the_event_type_and_can_be_edited(): void
    {
        $this->assertSame(['Prenup shoot', 'Ceremony', 'Reception'], array_column($this->wedding->parts, 'name'));
        $this->assertTrue($this->wedding->parts[0]['optional']);
        $this->assertEquals(15000, $this->wedding->parts[0]['price']);
        // a new package starts from its type's usual parts; an all-optional list is refused
        $owner = $this->actingAs($this->s->owner)->withSession(['mode' => 'supplier']);
        $owner->get(route('business.packages.create'))->assertOk()->assertSee('Parts of this event');
        $base = ['name' => 'Two-day Debut', 'description' => 'Rehearsal the day before and the debut night itself.', 'price' => 95000, 'max_guests' => 150, 'events' => ['Debut'], 'inclusions' => ['Host']];
        $this->post(route('business.packages.store'), $base + ['parts' => [['name' => 'Rehearsal', 'optional' => 1]]])->assertSessionHasErrors('parts');
        $this->post(route('business.packages.store'), $base + ['parts' => [['name' => 'Rehearsal', 'optional' => 1, 'price' => 5000], ['name' => 'Debut night']]])->assertRedirect();
        $k = EventPackage::where('name', 'Two-day Debut')->firstOrFail();
        $this->assertEquals([['name' => 'Rehearsal', 'optional' => true, 'price' => 5000], ['name' => 'Debut night', 'optional' => false, 'price' => 0]], $k->parts);
    }

    public function test_inquiry_with_several_dates_and_an_optional_part(): void
    {
        $prenup = today()->addDays(30)->toDateString();
        $day = today()->addDays(90)->toDateString();
        // a required part without a date is refused
        $this->ask([['include' => 1, 'date' => $prenup], ['date' => $day], []])->assertSessionHas('error', fn ($e) => str_contains($e, 'Reception'));
        $this->ask([['include' => 1, 'date' => $prenup, 'venue' => 'Beach, Batangas'], ['date' => $day, 'start' => '15:00', 'end' => '16:00'], ['date' => $day, 'start' => '18:00', 'end' => '23:00']])->assertRedirect();
        $b = EventBooking::where('customer_id', User::where('email', 'jose@demo.ph')->value('id'))->where('event_type', 'Wedding')->latest('id')->firstOrFail();
        $this->assertSame($prenup, $b->event_date->toDateString()); // first part: payments are due before it
        $this->assertSame(['Prenup shoot', 'Ceremony', 'Reception'], $b->parts->pluck('name')->all());
        $this->assertEquals(15000, $b->parts->first()->price);
        $this->assertSame('Beach, Batangas', $b->parts->first()->venue);
        $this->get(route('events.show', $b))->assertOk()->assertSee('Prenup shoot')->assertSee('3 parts');
        // the business's first quotation lists the optional part's price
        $this->actingAs($this->s->owner)->withSession(['mode' => 'supplier'])->get(route('business.bookings.show', $b))->assertOk()
            ->assertSee('Prenup shoot ('.\Carbon\Carbon::parse($prenup)->format('M j').')')->assertDontSee('Mark done');
        // without the optional part: two parts on one date
        $this->ask([[], ['date' => $day], ['date' => $day]], 'maria@demo.ph')->assertRedirect();
        $this->assertSame(2, EventBooking::where('customer_id', User::where('email', 'maria@demo.ph')->value('id'))->where('event_type', 'Wedding')->firstOrFail()->parts()->count());
    }

    public function test_every_part_date_must_be_free_and_parts_complete_one_by_one(): void
    {
        $day = today()->addDays(90)->toDateString();
        $prenup = today()->addDays(30)->toDateString();
        // another booking already holds the prenup date (Grand Fiesta: 1 event a day)
        $this->ask([[], ['date' => $prenup], ['date' => $prenup]], 'maria@demo.ph')->assertRedirect();
        EventBooking::latest('id')->first()->update(['status' => 'confirmed']);
        $this->ask([['include' => 1, 'date' => $prenup], ['date' => $day], ['date' => $day]])->assertSessionHas('error', fn ($e) => str_contains($e, 'fully booked on '.fmt_date($prenup)));

        // parts are done one by one; the event completes after the last part
        $b = EventBooking::where('status', 'confirmed')->where('event_type', 'Debut')->firstOrFail(); // demo: confirmed debut in 75 days
        $b->parts()->update(['date' => today()->toDateString()]);
        $b->parts()->create(['name' => 'After-party', 'date' => today()->addDays(2)->toDateString(), 'sort' => 1]);
        $owner = $this->actingAs($this->s->owner)->withSession(['mode' => 'supplier']);
        $first = $b->parts()->orderBy('date')->first();
        $owner->post(route('business.bookings.part-done', [$b, $first->id]))->assertSessionHas('success', fn ($m) => str_contains($m, '1 part to go'));
        $later = $b->parts()->whereNull('done_at')->first();
        $this->post(route('business.bookings.part-done', [$b, $later->id]))->assertSessionHas('error');
        $this->post(route('business.bookings.complete', $b))->assertSessionHas('error');
        $this->assertSame('confirmed', $b->fresh()->status);
    }
}
