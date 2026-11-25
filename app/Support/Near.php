<?php

namespace App\Support;

use App\Domain\Locations;

/**
 * Where the customer is renting near ("Renting near: Quezon City · Change").
 *
 * Read in this order: the choice made this visit (session), the choice saved on the account, the city of the
 * account's address. A city is enough (its centre is used); a pinned point is more precise. Every "near you"
 * figure (stock on item pages, which branches can serve, the branch picked at checkout) is worked out from it.
 * Unknown = no area limit: every branch counts, as before.
 */
class Near
{
    /** @return array{city:string, label:string, lat:float, lng:float, from:string}|null */
    public static function get(): ?array
    {
        if (is_array($s = session('near')) && isset($s['lat'], $s['lng'])) {
            return $s;
        }
        $u = auth()->user();
        if ($u && is_array($u->near) && isset($u->near['lat'], $u->near['lng'])) {
            return $u->near;
        }
        if ($u && $u->city_code && ($c = self::city($u->city_code))) {
            return $c + ['from' => 'address'];
        }

        return null;
    }

    /** [lat, lng] of where the customer is, or null. */
    public static function point(): ?array
    {
        $n = self::get();

        return $n ? [(float) $n['lat'], (float) $n['lng']] : null;
    }

    public static function label(): ?string
    {
        return self::get()['label'] ?? null;
    }

    /** Save a city (and optionally a pinned point inside the country). Returns the saved value or null when invalid. */
    public static function set(string $cityCode, ?float $lat = null, ?float $lng = null): ?array
    {
        $c = self::city($cityCode);
        if (! $c) {
            return null;
        }
        if ($lat !== null && $lng !== null && Locations::inBounds($lat, $lng)) {
            $c['lat'] = round($lat, 6);
            $c['lng'] = round($lng, 6);
        }
        $c['from'] = 'chosen';
        session(['near' => $c]);
        if ($u = auth()->user()) {
            $u->forceFill(['near' => $c])->saveQuietly();
        }

        return $c;
    }

    public static function clear(): void
    {
        session()->forget('near');
        if ($u = auth()->user()) {
            $u->forceFill(['near' => null])->saveQuietly();
        }
    }

    private static function city(string $code): ?array
    {
        $c = Locations::isCity($code) ? Locations::get($code) : null;

        return $c && $c['lat'] !== null ? ['city' => $code, 'label' => Locations::label($code), 'lat' => (float) $c['lat'], 'lng' => (float) $c['lng']] : null;
    }
}
