<?php

namespace App\Domain;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Address search (geocoding) and "what is at this pin" (reverse geocoding), for checkout delivery addresses and the
 * browsing location. One service for both, called only from the server (App\Http\Controllers\GeoController):
 *
 * - OpenStreetMap Nominatim (config partyrent.geocoder), limited to the country, results cached so the same query or
 *   pin is never asked twice, with a short timeout.
 * - The app's own list of regions / provinces / cities (database/data) always answers too: city search works offline,
 *   and a pin can always be placed in its nearest city when the service is down or unsure.
 *
 * Every result is turned into the app's structured address: regionCode, provinceCode, cityCode (our codes),
 * barangay, street, plus lat / lng and a label. "precise" = the service placed it at street / building level.
 */
class Geo
{
    private const SERVICE_TYPES = ['house', 'building', 'residential', 'road', 'street', 'amenity', 'shop', 'tourism', 'leisure', 'office'];

    public static function enabled(): bool
    {
        return (bool) config('partyrent.geocoder.enabled', true) && (string) config('partyrent.geocoder.url') !== '';
    }

    /** Places matching a query: local cities first (instant), then streets, landmarks and barangays from the service. */
    public static function search(string $q): array
    {
        $q = trim(mb_substr($q, 0, 120));
        if (mb_strlen($q) < 2) {
            return ['results' => [], 'service' => self::enabled()];
        }
        $local = self::localCities($q);
        $remote = [];
        $ok = true;
        if (self::enabled() && mb_strlen($q) >= 3) {
            $rows = self::ask('search', ['q' => $q, 'limit' => 6]);
            $ok = $rows !== null;
            foreach ((array) $rows as $r) {
                if ($x = self::fromService($r)) {
                    $remote[] = $x;
                }
            }
        }
        // service results (streets, landmarks) first when they exist, then matching cities not already covered
        $seen = [];
        $out = [];
        foreach (array_merge($remote, $local) as $x) {
            $k = $x['label'];
            if (! isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $x;
            }
        }

        return ['results' => array_slice($out, 0, 8), 'service' => $ok];
    }

    /** The address at a pin. Falls back to the nearest city / municipality (not precise) when the service cannot say. */
    public static function reverse(float $lat, float $lng): ?array
    {
        if (! Locations::inBounds($lat, $lng)) {
            return null;
        }
        $hit = self::enabled() ? self::ask('reverse', ['lat' => round($lat, 5), 'lon' => round($lng, 5), 'zoom' => 18]) : null;
        $x = is_array($hit) && empty($hit['error']) ? self::fromService($hit) : null;
        if ($x && $x['parts']['cityCode']) {
            return array_merge($x, ['lat' => $lat, 'lng' => $lng]); // keep the customer's exact pin
        }
        $c = Locations::nearestCity([$lat, $lng]);

        return $c ? ['label' => Locations::label($c['code']), 'lat' => $lat, 'lng' => $lng, 'precise' => false, 'source' => 'local',
            'parts' => self::parts($c['code'], '', '')] : null;
    }

    /** Cities / municipalities from our own list whose name contains the query (centre point, not precise). */
    private static function localCities(string $q): array
    {
        $n = mb_strtolower($q);
        $hits = array_filter(Locations::cities(), fn ($c) => $c['lat'] !== null && str_contains(mb_strtolower($c['name']), $n));
        usort($hits, fn ($a, $b) => [! str_starts_with(mb_strtolower($a['name']), $n), $a['name']] <=> [! str_starts_with(mb_strtolower($b['name']), $n), $b['name']]);

        return array_map(fn ($c) => ['label' => Locations::label($c['code']), 'lat' => (float) $c['lat'], 'lng' => (float) $c['lng'], 'precise' => false, 'source' => 'local',
            'parts' => self::parts($c['code'], '', '')], array_slice($hits, 0, 5));
    }

    /** A Nominatim result as our structured address; null when it is outside our places (no city we know). */
    private static function fromService(array $r): ?array
    {
        $a = (array) ($r['address'] ?? []);
        $cityName = $a['city'] ?? $a['town'] ?? $a['municipality'] ?? $a['village'] ?? null;
        $city = Locations::matchCity($cityName, $a['state'] ?? $a['county'] ?? $a['province'] ?? null, $a['region'] ?? null);
        if (! $city || ! isset($r['lat'], $r['lon'])) {
            return null;
        }
        $brgy = $a['quarter'] ?? $a['suburb'] ?? $a['neighbourhood'] ?? $a['village'] ?? $a['hamlet'] ?? '';
        if ($brgy !== '' && Locations::norm($brgy) === Locations::norm($city['name'])) {
            $brgy = '';
        }
        $street = trim(implode(' ', array_filter([$a['house_number'] ?? '', $a['road'] ?? ''])));
        $named = trim((string) ($r['name'] ?? ''));
        if ($named !== '' && $named !== ($a['road'] ?? '') && $named !== $brgy && $named !== $cityName) {
            $street = trim($named.($street !== '' ? ', '.$street : '')); // a building or landmark
        }
        $type = (string) ($r['type'] ?? $r['addresstype'] ?? '');

        return [
            'label' => self::label($street, $brgy, $city['code']), 'lat' => (float) $r['lat'], 'lng' => (float) $r['lon'],
            'precise' => in_array($type, self::SERVICE_TYPES, true) || $street !== '', 'source' => 'service',
            'parts' => self::parts($city['code'], $brgy, $street),
        ];
    }

    private static function parts(string $cityCode, string $brgy, string $street): array
    {
        $p = Locations::parentsOf($cityCode);

        return ['regionCode' => $p['regionCode'] ?? '', 'provinceCode' => $p['provinceCode'] ?? '', 'cityCode' => $cityCode, 'barangay' => $brgy, 'street' => $street];
    }

    private static function label(string $street, string $brgy, string $cityCode): string
    {
        return implode(', ', array_filter([$street, $brgy !== '' ? 'Brgy. '.preg_replace('/^(brgy\.?|barangay)\s+/i', '', $brgy) : '', Locations::label($cityCode)]));
    }

    /** One cached call to the service (null on failure). */
    private static function ask(string $what, array $params): ?array
    {
        $params += ['format' => 'jsonv2', 'addressdetails' => 1, 'accept-language' => 'en'];
        if ($what === 'search') {
            $b = config('partyrent.map.bounds');
            $params += ['countrycodes' => strtolower((string) config('partyrent.country', 'PH')), 'viewbox' => $b[0][1].','.$b[1][0].','.$b[1][1].','.$b[0][0], 'bounded' => 1];
        }
        $key = 'geo:'.$what.':'.md5(json_encode($params));
        $hit = Cache::get($key);
        if ($hit !== null) {
            return $hit ?: null;
        }
        try {
            $res = Http::timeout((int) config('partyrent.geocoder.timeout', 6))
                ->withHeaders(['User-Agent' => config('app.name', 'PartyRent').'/1.0 (+'.config('app.url').')'])
                ->get(rtrim((string) config('partyrent.geocoder.url'), '/').'/'.$what, $params);
            if (! $res->ok()) {
                return null; // not cached: try again next time
            }
            $data = (array) $res->json();
            Cache::put($key, $data, now()->addDays(7));

            return $data;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
