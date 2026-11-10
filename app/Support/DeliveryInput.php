<?php

namespace App\Support;

use App\Domain\Locations;
use App\Models\Supplier;
use Illuminate\Validation\ValidationException;

/**
 * Supplier delivery settings from the "Fulfillment" form (partials/delivery-settings).
 * Each zone row is a Region / Province / City picker plus a fee; the deepest place picked
 * decides the zone level, so "Region VII" alone covers the whole region and
 * "Region VII → Cebu → Cebu City" covers only Cebu City.
 */
class DeliveryInput
{
    public static function rules(): array
    {
        return [
            'delivery' => 'nullable|array',
            'delivery.mode' => 'nullable|in:weight,zones,distance',
            'delivery.reach' => 'nullable|in:local,island,inter',
            'free_ship' => 'nullable|in:0,1',
            'delivery.zones' => 'nullable|array|max:60',
            'delivery.zones.*.regionCode' => 'nullable|string|max:12',
            'delivery.zones.*.provinceCode' => 'nullable|string|max:12',
            'delivery.zones.*.cityCode' => 'nullable|string|max:12',
            'delivery.zones.*.fee' => 'nullable|numeric|min:0|max:1000000',
            'delivery.baseFee' => 'nullable|numeric|min:0|max:1000000',
            'delivery.perKm' => 'nullable|numeric|min:0|max:100000',
            'delivery.freeKm' => 'nullable|numeric|min:0|max:5000',
            'delivery.maxKm' => 'nullable|numeric|min:0|max:5000',
            'delivery.freeOver' => 'nullable|numeric|min:0|max:100000000',
            'delivery.roundTrip' => 'nullable|in:0,1',
            'delivery.chargePerTrip' => 'nullable|in:0,1',
        ];
    }

    /** Validated input → supplier.delivery. Throws a ValidationException for unusable settings when $strict. */
    public static function toSettings(?array $in, string $fulfillment, bool $strict = true): array
    {
        $in ??= [];
        $num = fn ($k) => r2(max(0, (float) ($in[$k] ?? 0)));
        $zones = [];
        $seen = [];
        $errors = [];
        foreach (array_values($in['zones'] ?? []) as $i => $row) {
            $code = null;
            $level = null;
            foreach (['city' => 'cityCode', 'province' => 'provinceCode', 'region' => 'regionCode'] as $lvl => $k) {
                if (! empty($row[$k])) {
                    [$code, $level] = [(string) $row[$k], $lvl];
                    break;
                }
            }
            if (! $code) {
                continue; // blank row
            }
            $place = Locations::get($code);
            $ok = $place && ($level === 'city' ? Locations::isCity($code) : $place['type'] === $level);
            if (! $ok) {
                $errors["delivery.zones.$i.regionCode"] = 'Delivery area '.($i + 1).' is not a known place.';

                continue;
            }
            if (isset($seen[$level.$code])) {
                continue; // duplicate area, keep the first fee
            }
            $seen[$level.$code] = true;
            $zones[] = ['level' => $level, 'code' => $code, 'fee' => r2(max(0, (float) ($row['fee'] ?? 0)))];
        }
        $mode = in_array($in['mode'] ?? '', ['weight', 'zones', 'distance'], true) ? $in['mode'] : 'weight';
        if ($strict && $fulfillment !== 'pickup' && $mode === 'zones' && ! $zones && ! $errors) {
            $errors['delivery.zones'] = 'Add at least one delivery area, or switch to PartyRent standard rates or distance-based rates.';
        }
        if ($strict && $errors) {
            throw ValidationException::withMessages($errors);
        }

        return array_merge(Supplier::DELIVERY_DEFAULTS, [
            'mode' => $mode, 'zones' => $zones,
            'reach' => in_array($in['reach'] ?? '', ['local', 'island', 'inter'], true) ? $in['reach'] : 'island',
            'baseFee' => $num('baseFee'), 'perKm' => $num('perKm'), 'freeKm' => $num('freeKm'), 'maxKm' => $num('maxKm'),
            'freeOver' => $num('freeOver'),
            'roundTrip' => ($in['roundTrip'] ?? '1') === '1', 'chargePerTrip' => ($in['chargePerTrip'] ?? '0') === '1',
        ]);
    }
}
