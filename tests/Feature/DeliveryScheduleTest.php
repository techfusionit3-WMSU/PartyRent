<?php

namespace Tests\Feature;

use App\Domain\DeliverySchedule;
use App\Models\CartLine;
use App\Models\AppNotification as Notification;
use App\Models\Product;
use App\Models\RentalRequest;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Deliver-by times from rental start and setup time; earlier-delivery requests the shop accepts or rejects. */
class DeliveryScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private function day(): string
    {
        return add_days(today_iso(), 30);
    }

    private function line(Product $p, string $start, string $dest = 'd1', array $x = []): array
    {
        return array_merge(['type' => 'item', 'ref_id' => $p->id, 'destId' => $dest, 'days' => [$this->day() => 1], 'time' => ['start' => $start, 'end' => '22:00']], $x);
    }

    public function test_deliver_by_is_the_earliest_start_minus_setup_time(): void
    {
        $s = Supplier::where('name', 'Happy Moments Party Rentals')->firstOrFail();
        [$a, $b] = Product::where('supplier_id', $s->id)->where('listing_type', 'rent')->take(2)->get()->all();
        $a->update(['setup_minutes' => 90]);
        $b->update(['setup_minutes' => 30]);
        inv()->flush();
        $this->assertSame('14:30', DeliverySchedule::deliverBy([$this->line($a, '16:00')], 'd1', $s));
        // several items: the one needed earliest decides (b starts 14:00 − 30 min = 13:30 < 14:30)
        $this->assertSame('13:30', DeliverySchedule::deliverBy([$this->line($a, '16:00'), $this->line($b, '14:00')], 'd1', $s));
        // lines for another location don't count
        $this->assertSame('14:30', DeliverySchedule::deliverBy([$this->line($a, '16:00'), $this->line($b, '09:00', 'd2')], 'd1', $s));
        $this->assertNull(DeliverySchedule::deliverBy([], 'd1', $s));
    }

    public function test_early_fee_is_charged_per_started_hour(): void
    {
        $s = Supplier::where('name', 'Happy Moments Party Rentals')->firstOrFail();
        $s->update(['delivery' => array_merge((array) $s->delivery, ['earlyPerHour' => 150])]);
        $this->assertEquals(150, DeliverySchedule::earlyFee($s, '14:00', '13:30'));
        $this->assertEquals(300, DeliverySchedule::earlyFee($s, '14:00', '12:15'));
        $this->assertEquals(0, DeliverySchedule::earlyFee($s, '14:00', '15:00'));
    }

    public function test_location_names_come_from_the_address_and_are_unique(): void
    {
        $d = DeliverySchedule::labels([
            ['id' => 'a', 'addr' => ['street' => 'Pilar Building, 2nd floor', 'barangay' => 'Barangay Zone IV']],
            ['id' => 'b', 'addr' => ['street' => 'Pilar Building', 'barangay' => 'Zone IV']],
            ['id' => 'c', 'addr' => []],
        ]);
        $this->assertSame(['Pilar Building, Brgy. Zone IV', 'Pilar Building, Brgy. Zone IV (2)', 'Location 3'], array_column($d, 'label'));
    }

    /** Checkout with an earlier-delivery request, then the shop accepts: fee added, time confirmed, customer told. */
    private function orderWithEarly(): array
    {
        $m = User::where('email', 'maria@demo.ph')->firstOrFail();
        $this->actingAs($m)->withSession(['mode' => 'customer']);
        CartLine::where('user_id', $m->id)->delete();
        $s = Supplier::where('name', 'Happy Moments Party Rentals')->firstOrFail();
        $s->update(['delivery' => array_merge((array) $s->delivery, ['mode' => 'zones', 'earlyPerHour' => 100])]);
        $p = Product::where('supplier_id', $s->id)->where('listing_type', 'rent')->where('status', 'active')->where('package_only', false)->firstOrFail();
        $this->post('/cart', ['type' => 'item', 'ref_id' => $p->id, 'days' => json_encode([$this->day() => 2])]);
        $dest = ['id' => 'd1', 'zoneId' => '', 'deliveryTime' => '03:00', 'addr' => ['cityCode' => '137404000', 'barangay' => 'Kamuning', 'street' => '23 Mabini St.']];
        $r = $this->checkout($s->id, ['contactName' => 'Maria', 'contactPhone' => '0917 555 0101', 'fulfillment' => 'delivery', 'destinations' => [$dest + ['earlyTime' => '__']]]);
        $this->assertStringContainsString('earlier delivery time', (string) $r->json('message').json_encode($r->json()));
        $std = RentalRequest::count();
        // the browser's deliveryTime (03:00) is ignored: the server works out the standard time
        $q = collect($this->postJson(route('checkout.quote'), ['shared' => [], 'orders' => [['supplier_id' => $s->id, 'cart_line_ids' => CartLine::where('user_id', $m->id)->pluck('id')->all(),
            'fulfillment' => 'delivery', 'destinations' => [$dest], 'plan' => 'full', 'eventDate' => $this->day()]]])->json('orders'))->first();
        $by = $q['drops'][0]['deliverBy'];
        $this->assertNotNull($by);
        $this->assertNotSame('03:00', $by);
        $early = DeliverySchedule::hhmm(DeliverySchedule::min($by) - 90);
        $this->checkout($s->id, ['contactName' => 'Maria', 'contactPhone' => '0917 555 0101', 'fulfillment' => 'delivery', 'destinations' => [$dest + ['earlyTime' => $early]]])->assertOk();
        $this->assertSame($std + 1, RentalRequest::count());
        $rr = RentalRequest::latest('id')->firstOrFail();
        $d = $rr->destinations[0];
        $this->assertSame($by, $d['deliveryTime']);
        $this->assertEquals(['time' => $early, 'fee' => 200.0, 'status' => 'pending'], array_intersect_key($d['early'], array_flip(['time', 'fee', 'status'])));

        return [$m, $s, $rr, $by, $early];
    }

    public function test_shop_accepts_an_earlier_delivery_and_the_fee_is_added(): void
    {
        [$m, $s, $rr, $by, $early] = $this->orderWithEarly();
        $total = (float) $rr->pricing['total'];
        $this->actingAs($s->owner)->withSession(['mode' => 'supplier']);
        $this->get(route('supplier.requests.show', $rr))->assertOk()->assertSee('Earlier delivery requested')->assertSee('Accept (+');
        $this->post(route('supplier.requests.early', [$rr, 'd1', 'accept']))->assertRedirect();
        $rr->refresh();
        $this->assertSame('accepted', $rr->destinations[0]['early']['status']);
        $this->assertSame($early, $rr->destinations[0]['deliveryTime']);
        $this->assertEquals($total + 200, $rr->pricing['total']);
        $this->assertEquals(200, $rr->pricing['earlyDeliveryFee']);
        $this->assertTrue(Notification::where('user_id', $m->id)->where('title', 'like', 'Earlier delivery confirmed%')->exists());
        // decided once only
        $this->post(route('supplier.requests.early', [$rr, 'd1', 'reject']))->assertSessionHas('error');
    }

    public function test_shop_rejects_an_earlier_delivery_and_nothing_changes(): void
    {
        [$m, $s, $rr, $by] = $this->orderWithEarly();
        $total = (float) $rr->pricing['total'];
        $this->actingAs($s->owner)->withSession(['mode' => 'supplier']);
        $this->post(route('supplier.requests.early', [$rr, 'd1', 'reject']))->assertRedirect();
        $rr->refresh();
        $this->assertSame('rejected', $rr->destinations[0]['early']['status']);
        $this->assertSame($by, $rr->destinations[0]['deliveryTime']);
        $this->assertEquals($total, $rr->pricing['total']);
        $this->assertTrue(Notification::where('user_id', $m->id)->where('title', 'like', 'Earlier delivery not possible%')->exists());
    }
}
