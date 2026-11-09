<?php

use App\Domain\Locations;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nationwide delivery.
 *  - Structured addresses (region → province → city + barangay/street/landmark) on users,
 *    suppliers and rental requests. `address` stays as the formatted display string and
 *    `city_code` is kept as its own indexed column for "delivers to my city" lookups.
 *  - suppliers.delivery_fee (one flat fee) becomes suppliers.delivery (zones or distance rates).
 *  - Optional per-product handling fee for bulky items.
 *
 * Existing rows are converted best-effort: cities are matched by name, and a supplier's old
 * flat fee becomes a zone covering its region (or every region when its city is unknown,
 * which matches the old "same fee anywhere" behaviour).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'suppliers', 'rental_requests'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->string('city_code', 12)->nullable()->index();
                $table->json('address_parts')->nullable();
            });
        }
        Schema::table('suppliers', fn (Blueprint $table) => $table->json('delivery')->nullable());
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('handling_fee', 10, 2)->default(0);
            $table->string('handling_per', 8)->default('line'); // line | unit
        });

        $this->convertUsers();
        $this->convertSuppliers();
        $this->convertRequests();

        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('delivery_fee'));
    }

    public function down(): void
    {
        Schema::table('suppliers', fn (Blueprint $table) => $table->decimal('delivery_fee', 10, 2)->default(0));
        foreach (DB::table('suppliers')->get(['id', 'delivery']) as $s) {
            $d = json_decode((string) $s->delivery, true) ?: [];
            $fee = ($d['mode'] ?? '') === 'distance' ? ($d['baseFee'] ?? 0) : (collect($d['zones'] ?? [])->max('fee') ?? 0);
            DB::table('suppliers')->where('id', $s->id)->update(['delivery_fee' => $fee]);
        }
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['handling_fee', 'handling_per']));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('delivery'));
        foreach (['users', 'suppliers', 'rental_requests'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropIndex(['city_code']);
                $table->dropColumn(['city_code', 'address_parts']);
            });
        }
    }

    private function parts(?array $city, string $street): ?array
    {
        if (! $city) {
            return null;
        }

        return array_merge(Locations::parentsOf($city['code']), ['barangay' => '', 'street' => $street, 'landmark' => '']);
    }

    private function convertUsers(): void
    {
        foreach (DB::table('users')->get(['id', 'address']) as $u) {
            $c = Locations::guessCityInText($u->address);
            if ($c) {
                DB::table('users')->where('id', $u->id)->update(['city_code' => $c['code'], 'address_parts' => json_encode($this->parts($c, (string) $u->address))]);
            }
        }
    }

    private function convertSuppliers(): void
    {
        foreach (DB::table('suppliers')->get(['id', 'city', 'address', 'fulfillment', 'delivery_fee']) as $s) {
            $c = Locations::findCityByName($s->city) ?? Locations::guessCityInText($s->address);
            $regions = $c ? [Locations::parentsOf($c['code'])['regionCode']] : array_column(Locations::regions(), 'code');
            $delivery = [
                'mode' => 'zones',
                'zones' => $s->fulfillment === 'pickup' ? [] : array_map(fn ($r) => ['level' => 'region', 'code' => $r, 'fee' => (float) $s->delivery_fee], $regions),
                'baseFee' => 0, 'perKm' => 0, 'freeKm' => 0, 'maxKm' => 0, 'freeOver' => 0, 'roundTrip' => true, 'chargePerTrip' => false,
            ];
            DB::table('suppliers')->where('id', $s->id)->update([
                'city_code' => $c['code'] ?? null, 'city' => $c['name'] ?? $s->city,
                'address_parts' => json_encode($this->parts($c, (string) $s->address)), 'delivery' => json_encode($delivery),
            ]);
        }
    }

    private function convertRequests(): void
    {
        foreach (DB::table('rental_requests')->where('fulfillment', 'delivery')->get(['id', 'address', 'pricing']) as $r) {
            $c = Locations::guessCityInText($r->address);
            $pricing = json_decode((string) $r->pricing, true) ?: [];
            // Old snapshots only knew a flat fee; record it in the new breakdown shape.
            $pricing['delivery'] ??= [
                'ok' => true, 'error' => null, 'mode' => 'flat', 'cityCode' => $c['code'] ?? null, 'cityLabel' => $c ? Locations::label($c['code']) : '',
                'base' => (float) ($pricing['deliveryFee'] ?? 0), 'distanceKm' => null, 'handling' => 0.0, 'trips' => 1, 'legs' => 1, 'waived' => false,
                'fee' => (float) ($pricing['deliveryFee'] ?? 0), 'breakdown' => [['label' => 'Delivery fee', 'amount' => (float) ($pricing['deliveryFee'] ?? 0)]],
            ];
            DB::table('rental_requests')->where('id', $r->id)->update([
                'city_code' => $c['code'] ?? null, 'address_parts' => json_encode($this->parts($c, (string) $r->address)), 'pricing' => json_encode($pricing),
            ]);
        }
    }
};
