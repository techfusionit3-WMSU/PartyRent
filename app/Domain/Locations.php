<?php

namespace App\Domain;

/**
 * Region → province → city/municipality lookup, loaded from the flat dataset named in
 * config('partyrent.locations'). All country-specific geography lives in that file;
 * this class only indexes it.
 *
 * Structured addresses everywhere in the app use this shape:
 *   ['regionCode', 'provinceCode', 'cityCode', 'barangay', 'street', 'landmark']
 */
class Locations
{
    public const CITY_TYPES = ['city', 'municipality'];

    /** @var array<string, array{code:string,name:string,type:string,parentCode:?string,lat:?float,lng:?float}>|null */
    private static ?array $byCode = null;

    /** @var array<string, list<string>> parent code => child codes (in file order) */
    private static array $children = [];

    private static function load(): void
    {
        if (self::$byCode !== null) {
            return;
        }
        self::$byCode = [];
        self::$children = [];
        foreach (require config('partyrent.locations') as [$code, $name, $type, $parent, $lat, $lng]) {
            $code = (string) $code;
            self::$byCode[$code] = [
                'code' => $code, 'name' => $name, 'type' => $type, 'parentCode' => $parent !== null ? (string) $parent : null,
                'lat' => $lat !== null ? (float) $lat : null, 'lng' => $lng !== null ? (float) $lng : null,
            ];
            self::$children[$parent ?? ''][] = $code;
        }
    }

    /** Forget the loaded dataset (tests / config changes). */
    public static function flush(): void
    {
        self::$byCode = null;
        self::$children = [];
    }

    public static function all(): array
    {
        self::load();

        return array_values(self::$byCode);
    }

    public static function get(?string $code): ?array
    {
        self::load();

        return $code !== null ? (self::$byCode[$code] ?? null) : null;
    }

    public static function name(?string $code): string
    {
        return self::get($code)['name'] ?? '';
    }

    public static function regions(): array
    {
        return self::kids('');
    }

    public static function provincesOf(?string $regionCode): array
    {
        $list = $regionCode ? self::kids($regionCode) : [];
        usort($list, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $list;
    }

    public static function citiesOf(?string $provinceCode): array
    {
        $list = $provinceCode ? self::kids($provinceCode) : [];
        usort($list, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $list;
    }

    /** Every city and municipality. */
    public static function cities(): array
    {
        return array_values(array_filter(self::all(), fn ($l) => in_array($l['type'], self::CITY_TYPES, true)));
    }

    public static function isCity(?string $code): bool
    {
        return in_array(self::get($code)['type'] ?? null, self::CITY_TYPES, true);
    }

    /** [region, province, city] for any code (shorter for regions/provinces); [] if unknown. */
    public static function path(?string $code): array
    {
        $out = [];
        $cur = self::get($code);
        $guard = 0;
        while ($cur && $guard++ < 6) {
            array_unshift($out, $cur);
            $cur = self::get($cur['parentCode']);
        }

        return $out;
    }

    /** Region and province codes for a city code. */
    public static function parentsOf(?string $cityCode): array
    {
        $p = self::path($cityCode);

        return ['regionCode' => $p[0]['code'] ?? null, 'provinceCode' => $p[1]['code'] ?? null, 'cityCode' => $p[2]['code'] ?? null];
    }

    /** "Cebu City, Cebu" — province omitted when it only groups (NCR) or repeats the city name. */
    public static function label(?string $cityCode): string
    {
        $p = self::path($cityCode);
        if (count($p) < 3) {
            return $p ? end($p)['name'] : '';
        }

        return $p[2]['name'].($p[1]['name'] !== $p[2]['name'] ? ', '.$p[1]['name'] : '');
    }

    /** Approximate road distance in km between two city centres: haversine × road factor, 1 decimal. Null if either is unknown. */
    public static function distanceKm(?string $a, ?string $b): ?float
    {
        $x = self::get($a);
        $y = self::get($b);
        if (! $x || ! $y || $x['lat'] === null || $y['lat'] === null) {
            return null;
        }
        if ($x['code'] === $y['code']) {
            return 0.0;
        }

        return self::roadKm([$x['lat'], $x['lng']], [$y['lat'], $y['lng']]);
    }

    /** Road distance from a point (e.g. a shop pin) to a city's centre. */
    public static function distanceKmFrom(?array $from, ?string $cityCode): ?float
    {
        $y = self::get($cityCode);
        if (! $from || ! $y || $y['lat'] === null) {
            return null;
        }

        return self::roadKm($from, [$y['lat'], $y['lng']]);
    }

    /** Haversine × road factor, 1 decimal. */
    public static function roadKm(array $a, array $b): float
    {
        return round(self::straightKm($a, $b) * (float) config('partyrent.road_factor', 1.3), 1);
    }

    public static function straightKm(array $a, array $b): float
    {
        $rad = M_PI / 180;
        $dLat = ($b[0] - $a[0]) * $rad;
        $dLng = ($b[1] - $a[1]) * $rad;
        $h = sin($dLat / 2) ** 2 + cos($a[0] * $rad) * cos($b[0] * $rad) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }

    /** Is a point inside the configured map bounds (the country)? */
    public static function inBounds(float $lat, float $lng): bool
    {
        [[$s, $w], [$n, $e]] = config('partyrent.map.bounds');

        return $lat >= $s && $lat <= $n && $lng >= $w && $lng <= $e;
    }

    /** Find a city/municipality by name (case-insensitive, "City" suffix optional). */
    public static function findCityByName(?string $name): ?array
    {
        $n = self::norm((string) $name);
        if ($n === '') {
            return null;
        }
        foreach (self::cities() as $c) {
            if (self::norm($c['name']) === $n) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Best-effort: the city/municipality named in free text ("... Loyola Heights, Quezon City").
     * Longest names first so "San Jose del Monte" beats "San Jose"; whole words only.
     */
    public static function guessCityInText(?string $text): ?array
    {
        $t = ' '.mb_strtolower((string) $text).' ';
        static $sorted = null;
        $sorted ??= (function () {
            $c = self::cities();
            usort($c, fn ($a, $b) => mb_strlen(self::norm($b['name'])) <=> mb_strlen(self::norm($a['name'])));

            return $c;
        })();
        foreach ($sorted as $c) {
            $n = self::norm($c['name']);
            if (mb_strlen($n) >= 4 && preg_match('/(?<![\p{L}])'.preg_quote($n, '/').'(?![\p{L}])/u', $t)) {
                return $c;
            }
        }

        return null;
    }

    public static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/^city of\s+/u', '', $s);
        $s = preg_replace('/\s+city$/u', '', $s);

        return trim(preg_replace('/\s*\(.*\)$/u', '', $s));
    }

    /** Normalise and validate a structured address; city must belong to province must belong to region. */
    public static function cleanAddress(array $a): array
    {
        $city = (string) ($a['cityCode'] ?? '');
        $parents = self::isCity($city) ? self::parentsOf($city) : ['regionCode' => null, 'provinceCode' => null, 'cityCode' => null];

        return [
            'regionCode' => $parents['regionCode'], 'provinceCode' => $parents['provinceCode'], 'cityCode' => $parents['cityCode'],
            'barangay' => trim((string) ($a['barangay'] ?? '')), 'street' => trim((string) ($a['street'] ?? '')), 'landmark' => trim((string) ($a['landmark'] ?? '')),
        ];
    }

    /** "12 Mabini St., Brgy. Kamuning, Quezon City, Metro Manila (near ...)" */
    public static function formatAddress(?array $a, bool $withLandmark = true): string
    {
        if (! $a) {
            return '';
        }
        $parts = array_filter([
            $a['street'] ?? '',
            ($a['barangay'] ?? '') !== '' ? (preg_match('/^(brgy|barangay)\b/i', $a['barangay']) ? $a['barangay'] : 'Brgy. '.$a['barangay']) : '',
            self::label($a['cityCode'] ?? null),
        ], fn ($x) => $x !== '');
        $out = implode(', ', $parts);
        if ($withLandmark && ($a['landmark'] ?? '') !== '') {
            $out .= ' (near '.$a['landmark'].')';
        }

        return $out;
    }

    /** Compact dataset for the browser: [code, name, type, parent, lat, lng] rows. */
    public static function forClient(): array
    {
        $out = [];
        foreach (self::all() as $l) {
            $out[] = $l['lat'] !== null ? [$l['code'], $l['name'], $l['type'], $l['parentCode'], $l['lat'], $l['lng']] : [$l['code'], $l['name'], $l['type'], $l['parentCode']];
        }

        return $out;
    }

    private static function kids(string $parent): array
    {
        self::load();

        return array_map(fn ($c) => self::$byCode[$c], self::$children[$parent] ?? []);
    }
}
