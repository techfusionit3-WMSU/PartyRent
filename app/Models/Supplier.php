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
            'company' => 'array', 'application' => 'array', 'payment_plans' => 'array', 'time_settings' => 'array',
            'delivery_zones' => 'array', 'services' => 'array', 'damage_policy' => 'array',
            'arrangements' => 'array',
            'policy' => 'array',
            'delivery' => 'array',
            'free_ship' => 'boolean',
            'address_parts' => 'array',
            'portfolio' => 'array', 'business_profile' => 'array',
            'rating' => 'float', 'review_count' => 'integer',
            'lat' => 'float',
            'lng' => 'float',
            'applied_at' => 'datetime',
            'approved_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'biz_verified_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function staff(): HasMany { return $this->hasMany(ShopStaff::class); }

    public const REG_TYPES = ['dti' => 'DTI (sole proprietor)', 'sec' => 'SEC (corporation / partnership)', 'cda' => 'CDA (cooperative)'];

    public function isBusiness(): bool { return $this->account_type === 'business'; }

    /**
     * Event company (applied as Organization / Company): books whole events by quotation, works on-site from a
     * physical workplace, no 1-to-1 item rentals. Not the same as isBusiness(): a solo item-rental shop can
     * verify its business papers (account_type business) and stay a rental shop.
     */
    public function isOrganization(): bool { return $this->type === 'organization' && self::eventCompanies(); }

    /** Is the event-company module on? (config partyrent.event_companies; off: PartyRent only rents items) */
    public static function eventCompanies(): bool { return (bool) config('partyrent.event_companies'); }

    /** "What your business offers" (business suppliers). */
    public const EVENT_SIZES = ['up_to_50' => 'Up to 50 guests', '50_150' => '50–150 guests', '150_300' => '150–300 guests', '300_plus' => '300+ guests'];

    public const OFFER_SUGGESTIONS = ['Full event coordination', 'Styling & décor', 'Catering', 'Lights & sound', 'Tent & venue setup', 'Host / emcee', 'Photo & video', 'Party entertainment', 'Event furniture', 'Setup & teardown'];

    public const PORTFOLIO_MIN = 3;

    public const PORTFOLIO_MAX = 8;

    public function profile(string $k, $def = null) { return data_get($this->business_profile ?? [], $k, $def); }

    public function logoUrl(): ?string { return $this->logo_path ? asset('storage/'.$this->logo_path) : null; }

    public function eventPackages(): HasMany { return $this->hasMany(EventPackage::class); }
    /** Kinds of events this business applied for / handles (App\Domain\EventTypes). */
    public function eventTypes(): HasMany { return $this->hasMany(SupplierEventType::class); }
    public function eventBookings(): HasMany { return $this->hasMany(EventBooking::class); }

    /** Business booking settings (service areas, setup time, payment schedule), with defaults. */
    public function bizSettings(): array { return array_replace(\App\Domain\Events::SETTINGS, (array) $this->profile('settings', [])); }
    public function isVerifiedBusiness(): bool { return $this->isBusiness() && $this->biz_status === 'verified'; }
    public function accountLabel(): string { return $this->isBusiness() ? 'Business' : 'Personal'; }
    public function products(): HasMany { return $this->hasMany(Product::class); }
    public function locations(): HasMany { return $this->hasMany(InventoryLocation::class)->orderBy('id'); }
    /** Branches (same rows as locations): the main branch first. */
    public function branches(): HasMany { return $this->hasMany(InventoryLocation::class)->orderByDesc('is_main')->orderBy('id'); }
    public function payouts(): HasMany { return $this->hasMany(Payout::class); }
    public function payoutBatches(): HasMany { return $this->hasMany(PayoutBatch::class); }

    /** Payment methods customers can choose now: cash is paused while the shop owes PartyRent too long. */
    public function acceptedMethods(): array
    {
        $m = $this->methods ?? [];

        return in_array('cash', $m, true) && \App\Domain\Payouts::cashPaused($this) ? array_values(array_diff($m, ['cash'])) : $m;
    }

    public function hasPayoutAccount(): bool { return (bool) $this->payout_method && $this->payout_account_name && $this->payout_account_no; }

    public function payoutAccountLabel(): string
    {
        return $this->hasPayoutAccount() ? ($this->payout_method === 'gcash' ? 'GCash' : ($this->payout_bank ?: 'Bank')).' · '.$this->payout_account_name.' · '.$this->payout_account_no : 'Not set';
    }
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

    /** Legacy delivery editor defaults (old Shop settings form; replaced by the v4 zone editor). */
    public const DELIVERY_DEFAULTS = ['mode' => 'zones', 'reach' => 'island', 'zones' => [], 'baseFee' => 0, 'perKm' => 0, 'freeKm' => 0, 'maxKm' => 0, 'freeOver' => 0, 'roundTrip' => true, 'chargePerTrip' => false];

    /** Legacy delivery settings (kept only so old data still reads). v4 uses delivery_zones + delivery_fee. */
    public function deliverySettings(): array
    {
        return $this->delivery ?? [];
    }

    /** v4: every address can be delivered to — named zones have their own fee, other areas use delivery_fee. */
    public function deliversTo(?string $cityCode): ?bool
    {
        return $this->offersDelivery();
    }

    public function offersDelivery(): bool
    {
        return $this->fulfillment !== 'pickup';
    }

    /** Short public description of delivery rates (zones + the standard rate). */
    public function deliverySummary(): string
    {
        if (! $this->offersDelivery()) {
            return 'Pickup only';
        }
        $fees = array_merge(array_map('floatval', array_column($this->delivery_zones ?? [], 'fee')), [(float) $this->delivery_fee]);
        $n = count($this->delivery_zones ?? []);

        return ($n ? $n.' delivery zone'.($n > 1 ? 's' : '').' · ' : '').(min($fees) == max($fees) ? money_short(min($fees)) : 'from '.money_short(min($fees)));
    }

    /** Named delivery zones with their fees, plus the standard rate. */
    public function deliveryAreas(): array
    {
        return array_merge(array_map(fn ($z) => $z['name'].' — '.money($z['fee']), $this->delivery_zones ?? []), ['Other areas (standard rate) — '.money($this->delivery_fee)]);
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
