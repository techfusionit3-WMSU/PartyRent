<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'methods' => 'array',
            'arrangements' => 'array',
            'policy' => 'array',
            'delivery' => 'array',
            'address_parts' => 'array',
            'rating' => 'float', 'review_count' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
            'applied_at' => 'datetime',
            'approved_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function products(): HasMany { return $this->hasMany(Product::class); }
    public function packages(): HasMany { return $this->hasMany(Package::class); }
    public function rentalRequests(): HasMany { return $this->hasMany(RentalRequest::class); }
    public function reviews(): HasMany { return $this->hasMany(Review::class); }

    public function scopeApproved($q) { return $q->where('status', 'approved'); }

    public function pol(string $key, mixed $default = null): mixed
    {
        return ($this->policy ?? [])[$key] ?? $default;
    }

    /** Where the supplier operates from: [label, explanation shown to the supplier]. */
    public const LOCATION_TYPES = [
        'shop' => ['Physical shop or warehouse', 'Customers see your exact pin and address, and can pick up there.'],
        'home' => ['Home-based', 'Customers only see your city. Your exact pin and address are shared with a customer after you accept their pickup request.'],
        'none' => ['No pickup location', 'You deliver everything, so customers never come to you. A pin is optional and only used to measure delivery distance.'],
    ];

    public function hasPin(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /** Exact location may be shown to anyone. */
    public function isPublicLocation(): bool
    {
        return $this->location_type === 'shop';
    }

    public function offersPickup(): bool
    {
        return $this->fulfillment !== 'delivery' && $this->location_type !== 'none';
    }

    /** Address safe to show publicly. */
    public function publicAddress(): string
    {
        $area = \App\Domain\Locations::label($this->city_code) ?: $this->city;

        return match ($this->location_type) {
            'home' => 'Home-based in '.$area,
            'none' => 'Online seller based in '.$area,
            default => $this->address,
        };
    }

    /** Where distance-based delivery is measured from: the pin, else the city centre. */
    public function originCoords(): ?array
    {
        if ($this->hasPin()) {
            return [$this->lat, $this->lng];
        }
        $c = \App\Domain\Locations::get($this->city_code);

        return $c && $c['lat'] !== null ? [$c['lat'], $c['lng']] : null;
    }

    public function directionsUrl(): ?string
    {
        return $this->hasPin() ? 'https://www.google.com/maps/dir/?api=1&destination='.$this->lat.','.$this->lng : null;
    }

    /** Delivery settings with every key present (see Pricing::deliveryQuote for how they apply). */
    public const DELIVERY_DEFAULTS = [
        'mode' => 'zones',          // zones | distance
        'zones' => [],              // [{level: city|province|region, code, fee}] — most specific match wins
        'baseFee' => 0, 'perKm' => 0, 'freeKm' => 0, 'maxKm' => 0, // distance mode; maxKm 0 = no limit
        'freeOver' => 0,            // items subtotal at which the trip fee is waived; 0 = off
        'roundTrip' => true,        // true: one fee covers drop-off + retrieval; false: each leg is charged
        'chargePerTrip' => false,   // true: each block of consecutive rental dates is its own trip
    ];

    public function deliverySettings(): array
    {
        return array_merge(self::DELIVERY_DEFAULTS, $this->delivery ?? []);
    }

    /** Memoised delivery quote with no items, used for "delivers to your city" badges. */
    private array $reach = [];

    public function deliveryTo(?string $cityCode): array
    {
        return $this->reach[$cityCode ?? ''] ??= \App\Domain\Pricing::deliveryQuote($this, [], 0, $cityCode);
    }

    /** null when no city is known. */
    public function deliversTo(?string $cityCode): ?bool
    {
        return $cityCode ? $this->deliveryTo($cityCode)['ok'] : null;
    }

    public function offersDelivery(): bool
    {
        return $this->fulfillment !== 'pickup';
    }

    /** Short public description of delivery rates, e.g. "Delivers to 3 areas · from ₱500" or "₱300 + ₱25/km, up to 60 km". */
    public function deliverySummary(): string
    {
        if (! $this->offersDelivery()) {
            return 'Pickup only';
        }
        $d = $this->deliverySettings();
        if ($d['mode'] === 'distance') {
            $s = money_short($d['baseFee']).($d['perKm'] > 0 ? ' + '.money_short($d['perKm']).'/km' : '');
            if ($d['freeKm'] > 0 && $d['perKm'] > 0) {
                $s .= ' after '.(float) $d['freeKm'].' km';
            }
            $s .= $d['maxKm'] > 0 ? ', up to '.(float) $d['maxKm'].' km' : '';
        } else {
            $fees = array_column($d['zones'], 'fee');
            if (! $fees) {
                return 'No delivery areas set';
            }
            $s = count($fees).' delivery area'.(count($fees) > 1 ? 's' : '').' · '.(min($fees) == max($fees) ? money_short(min($fees)) : 'from '.money_short(min($fees)));
        }

        return $s.($d['freeOver'] > 0 ? ' · free over '.money_short($d['freeOver']) : '');
    }

    /** Human list of the areas a zones-mode supplier covers. */
    public function deliveryAreas(): array
    {
        $d = $this->deliverySettings();
        if ($d['mode'] === 'distance') {
            return [$d['maxKm'] > 0 ? 'Within '.(float) $d['maxKm'].' km of '.$this->city : 'Anywhere reachable from '.$this->city];
        }

        return array_map(fn ($z) => \App\Domain\Locations::name($z['code']).' — '.money($z['fee']), $d['zones']);
    }

    public function fulfillmentLabel(): string
    {
        return ['both' => 'Delivery & Pickup', 'delivery' => 'Delivery only', 'pickup' => 'Pickup only'][$this->fulfillment] ?? $this->fulfillment;
    }

    public function depositLabel(): string
    {
        return $this->pol('depositType') === 'percent' ? $this->pol('depositValue').'% of items' : money($this->pol('depositValue', 0));
    }

    public function downLabel(): string
    {
        return $this->pol('downType') === 'percent' ? $this->pol('downValue').'%' : money($this->pol('downValue', 0));
    }
}
