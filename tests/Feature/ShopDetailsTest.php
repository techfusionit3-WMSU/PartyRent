<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\User;
use App\Support\WeeklyHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Supplier application Step 4 / Shop Settings: hours per day, pickup location type, extra policies, logo image. */
class ShopDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected string $seeder = \Database\Seeders\DemoSeeder::class;

    private const MARIKINA = ['regionCode' => '130000000', 'provinceCode' => '137000000', 'cityCode' => '137402000'];

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** A real PNG of $n × $n pixels (GD is not needed). */
    private function png(string $name, int $n): UploadedFile
    {
        $chunk = fn ($t, $d) => pack('N', strlen($d)).$t.$d.pack('N', crc32($t.$d));
        $raw = str_repeat("\0".str_repeat("\xE1\x1D\x74", $n), $n);
        $bin = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $n, $n, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent($name, $bin);
    }

    private function week(array $over = []): array
    {
        $w = [];
        foreach (array_keys(WeeklyHours::DAYS) as $d) {
            $w[$d] = ['closed' => $d === 'sun' ? '1' : '0', 'open' => '09:00', 'close' => '18:00'];
        }

        return array_replace_recursive($w, $over);
    }

    private function application(array $over): array
    {
        return array_merge([
            'app' => ['type' => 'individual',
                'personal' => ['fullName' => 'Jose Garcia', 'birthDate' => '1990-04-02', 'address' => '7 Sampaguita St., Marikina Heights, Marikina', 'phone' => '0923 555 0707', 'email' => 'jose@demo.ph'],
                'identity' => ['idType' => 'Philippine National ID', 'idNumber' => '1234-5678-9012'], 'business' => ['productTypes' => 'speakers, lights'],
                'payout' => ['method' => 'bank', 'provider' => 'BDO', 'accountName' => 'Jose Garcia', 'accountNumber' => '001234567890']],
            'docs' => ['gov_id' => UploadedFile::fake()->create('id.pdf', 50, 'application/pdf')],
            'name' => 'Jose Sounds', 'description' => 'Sound systems and lights for parties in Marikina.', 'logo' => '🎵',
            'phone' => '0923 555 0707', 'email' => 'jose@demo.ph', 'weekly_hours' => $this->week(),
            'location_type' => 'warehouse', 'shop_addr' => self::MARIKINA + ['barangay' => 'Marikina Heights', 'street' => '7 Sampaguita St.'],
            'lat' => 14.6520, 'lng' => 121.1100, 'pin_confirmed' => '1', 'fulfillment' => 'both', 'methods' => ['cash'], 'cancelDays' => 2,
            'cancellation' => 'Free up to 2 days before.', 'returns' => 'Return clean.', 'conditions' => 'Renter pays for damage.',
            'custom_policies' => [['title' => 'Late returns', 'body' => '₱200 per hour after the agreed return time.'], ['title' => '', 'body' => '']],
        ], $over);
    }

    public function test_weekly_hours_are_checked_and_summarised(): void
    {
        $w = WeeklyHours::fromInput($this->week(['sat' => ['open' => '10:00', 'close' => '15:00']]));
        $this->assertSame('Mon–Fri 9:00 AM – 6:00 PM · Sat 10:00 AM – 3:00 PM · Sun closed', WeeklyHours::summary($w));
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        WeeklyHours::fromInput($this->week(['mon' => ['close' => '08:00']]));
    }

    public function test_application_step_4_saves_hours_warehouse_policies_and_logo(): void
    {
        Storage::fake('public');
        $this->actingAs(User::where('email', 'jose@demo.ph')->firstOrFail())->withSession(['mode' => 'customer']);
        $this->get(route('start-selling'))->assertOk()->assertSee('Copy to…')->assertSee('Shop policies')->assertSee('Upload a logo image')->assertSee('Warehouse')->assertSee('Search the address or a landmark');
        // the "Continue" check catches bad hours and duplicate policies on step 4
        $bad = $this->application(['weekly_hours' => $this->week(['tue' => ['open' => '18:00', 'close' => '09:00']]),
            'custom_policies' => [['title' => 'Late returns', 'body' => 'Charged per hour.'], ['title' => 'late returns', 'body' => 'Another rule here.']]]);
        $res = $this->postJson('/start-selling/check', $bad + ['step' => 3])->assertStatus(422);
        $this->assertArrayHasKey('weekly_hours.tue', $res->json('errors'));
        $this->assertArrayHasKey('custom_policies.1.title', $res->json('errors'));
        $this->post('/start-selling', $this->application(['weekly_hours' => $this->week(array_fill_keys(array_keys(WeeklyHours::DAYS), ['closed' => '1']))]))->assertSessionHasErrors('weekly_hours');
        $this->post('/start-selling', $this->application(['logo_file' => UploadedFile::fake()->create('logo.gif', 10, 'image/gif')]))->assertSessionHasErrors('logo_file');

        $logo = $this->png('logo.png', 200);
        $this->post('/start-selling', $this->application(['logo_file' => $logo]))->assertSessionHas('success');
        $s = Supplier::where('name', 'Jose Sounds')->firstOrFail();
        $this->assertSame('warehouse', $s->location_type);
        $this->assertTrue($s->isPublicLocation());
        $this->assertTrue($s->weekly_hours['sun']['closed']);
        $this->assertSame('Mon–Sat 9:00 AM – 6:00 PM · Sun closed', $s->hours);
        $this->assertSame([['title' => 'Late returns', 'body' => '₱200 per hour after the agreed return time.']], $s->policy['custom']);
        $this->assertNotNull($s->logo_path);
        Storage::disk('public')->assertExists($s->logo_path);
    }

    public function test_shop_settings_and_shop_page(): void
    {
        Storage::fake('public');
        $s = Supplier::where('name', 'Happy Moments Party Rentals')->firstOrFail();
        $this->actingAs($s->owner)->withSession(['mode' => 'supplier']);
        $this->get(route('supplier.shop'))->assertOk()->assertSee('Copy to…')->assertSee('Upload a logo image');
        $base = ['section' => 'profile', 'name' => $s->name, 'description' => $s->description, 'logo' => '🎉', 'phone' => $s->phone, 'email' => $s->email,
            'location_type' => $s->location_type, 'shop_addr' => $s->address_parts, 'lat' => $s->lat ?? \App\Domain\Locations::get($s->city_code)['lat'], 'lng' => $s->lng ?? \App\Domain\Locations::get($s->city_code)['lng']];
        $this->post(route('supplier.shop.update'), $base + ['weekly_hours' => $this->week(['sun' => ['closed' => '0', 'open' => '10:00', 'close' => '14:00']]), 'logo_file' => $this->png('l.png', 120)])->assertSessionHas('success');
        $s->refresh();
        $this->assertSame('Mon–Sat 9:00 AM – 6:00 PM · Sun 10:00 AM – 2:00 PM', $s->hours);
        $this->assertNotNull($path = $s->logo_path);
        // remove the image: back to the emoji
        $this->post(route('supplier.shop.update'), $base + ['logo_remove' => '1'])->assertSessionHas('success');
        $this->assertNull($s->fresh()->logo_path);
        Storage::disk('public')->assertMissing($path);
        // policies tab: add, then edit by sending the new list; duplicates refused
        $pol = ['section' => 'policies', 'cancellation' => 'Free up to 3 days.', 'returns' => 'Return clean.', 'conditions' => 'Renter pays for damage.'];
        $this->post(route('supplier.shop.update'), $pol + ['custom_policies' => [['title' => 'Weather', 'body' => 'Tents are not set up during storms.'], ['title' => 'WEATHER', 'body' => 'Duplicate entry here.']]])->assertSessionHasErrors('custom_policies.1.title');
        $this->post(route('supplier.shop.update'), $pol + ['custom_policies' => [['title' => 'Weather', 'body' => 'Tents are not set up during storms.']]])->assertSessionHas('success');
        $this->assertCount(1, $s->fresh()->policy['custom']);
        $this->get(route('suppliers.show', [$s, 'tab' => 'policies']))->assertOk()->assertSee('Weather')->assertSee('Tents are not set up during storms.')->assertSee('Sunday');
    }
}
