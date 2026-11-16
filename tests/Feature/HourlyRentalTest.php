<?php

namespace Tests\Feature;

use App\Domain\Hours;
use App\Domain\Pricing;
use App\Domain\Rentals;
use App\Models\CartLine;
use App\Models\Hold;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Items rented by the hour: only overlapping times (plus the buffer) compete for units. */
class HourlyRentalTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private Product $p;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->date = add_days(today_iso(), 10);
        $this->p = Product::where('listing_type', 'rent')->where('status', 'active')->firstOrFail();
        Hold::where('product_id', $this->p->id)->delete();
        $this->p->update(['rental_mode' => 'hour', 'unit' => 'unit / hour', 'total_stock' => 1, 'damaged' => 0, 'missing' => 0,
            'tiers' => [['min' => 1, 'max' => null, 'price' => 500]], 'min_hours' => 3, 'buffer_min' => 60]);
        // Nothing else books this item on that date.
        RentalRequest::all()->each(function ($r) {
            $r->lines = array_values(array_filter($r->lines ?? [], fn ($l) => ! ($l['type'] === 'item' && (int) $l['ref_id'] === $this->p->id)));
            $r->saveQuietly();
        });
        // An accepted booking 9:00–12:00.
        $r = RentalRequest::where('status', 'accepted')->firstOrFail();
        $r->lines = array_merge($r->lines, [['id' => 'lx', 'type' => 'item', 'ref_id' => $this->p->id, 'days' => [$this->date => 1], 'time' => ['from' => '09:00', 'to' => '12:00']]]);
        $r->saveQuietly();
        inv()->flush();
    }

    private function customer(): User
    {
        $owner = $this->p->supplier->owner_id;

        return User::where('email', 'jose@demo.ph')->where('id', '!=', $owner)->firstOrFail();
    }

    private function add(string $from, string $to, int $qty = 1): array
    {
        inv()->flush();

        return Rentals::addToCart($this->customer(), 'item', $this->p->id, [$this->date => $qty], null, ['from' => $from, 'to' => $to]);
    }

    public function test_hours_helper(): void
    {
        $this->assertSame([840, 1080], Hours::window(['from' => '14:00', 'to' => '18:00']));
        $this->assertNull(Hours::window(['from' => '18:00', 'to' => '14:00']));
        $this->assertSame([1200, 1440], Hours::window(['from' => '20:00', 'to' => '24:00']));
        $this->assertSame('2:00 PM – 6:00 PM (4 h)', Hours::label(['from' => '14:00', 'to' => '18:00']));
        $this->assertSame(2, Hours::peak([[0, 60, 1], [30, 90, 1], [90, 120, 1]]));
        $this->assertSame(1, Hours::peak([[0, 60, 1], [60, 120, 1]])); // back to back
    }

    public function test_rest_of_the_day_stays_bookable(): void
    {
        $this->assertSame(0, inv()->availableAt($this->p->id, $this->date, 10 * 60, 11 * 60));
        $this->assertSame(1, inv()->availableAt($this->p->id, $this->date, 14 * 60, 17 * 60));

        $this->assertTrue($this->add('14:00', '17:00')['ok']);
        $line = CartLine::where('ref_id', $this->p->id)->firstOrFail();
        $this->assertSame(['start' => '14:00', 'end' => '17:00'], $line->time); // v4 time keys
        $q = Pricing::quoteLine($line->toLine());
        $this->assertSame(1500.0, $q['total']); // ₱500 × 3 h × 1
        $this->assertStringContainsString('2:00 PM – 5:00 PM', $q['name']);
    }

    public function test_overlap_and_buffer_are_refused(): void
    {
        $this->assertFalse($this->add('11:00', '14:00')['ok']);   // overlaps 9–12
        // v4: the shop's recovery (1 h after a return) + preparation (1 h before a release) keep units apart
        $this->assertFalse($this->add('12:30', '15:30')['ok']);
        $this->assertFalse($this->add('13:00', '16:00')['ok']);
        $this->assertTrue($this->add('14:00', '17:00')['ok']);    // 12:00 + 1 h recovery + 1 h preparation
    }

    public function test_minimum_hours_and_a_time_is_required(): void
    {
        $this->assertStringContainsString('minimum rental duration of 3 hours', $this->add('14:00', '16:00')['error']);
        // v4 (Slots): a timed booking may span several dates — start on the first, end on the last.
        $this->assertFalse(Rentals::addToCart($this->customer(), 'item', $this->p->id, [$this->date => 1])['ok']); // no time
    }

    public function test_two_requests_same_day_can_both_be_accepted(): void
    {
        $this->assertTrue($this->add('15:00', '19:00')['ok']);
        $lines = Rentals::cartLinesFor($this->customer(), $this->p->supplier_id);
        $this->assertTrue(inv()->check($lines)['ok']);
        // the same window clashes with itself when booked twice in one request
        $twice = [$lines[0], array_merge($lines[0], ['id' => 'c-other'])];
        $this->assertFalse(inv()->check($twice)['ok']);

        $this->get(route('api.slots', ['id' => $this->p->id, 'date' => $this->date, 'from' => '15:00', 'to' => '19:00']))
            ->assertOk()->assertJsonPath('available', 1)->assertJsonPath('busy.0.label', '9:00 AM – 12:00 PM');
    }

    public function test_pages_show_the_hourly_booking(): void
    {
        $this->actingAs($this->customer())->get(route('products.show', $this->p))->assertOk()
            ->assertSee('Start time')->assertSee('id="hourForm"', false)->assertSee('/ unit / hour');
        $this->actingAs($this->p->supplier->owner)->withSession(['mode' => 'supplier'])->get(route('supplier.products.edit', $this->p))->assertOk()
            ->assertSee('By the hour')->assertSee('Gap between bookings');
    }
}
