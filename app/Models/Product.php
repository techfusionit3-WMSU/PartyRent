<?php

namespace App\Models;

use App\Domain\Pricing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tiers' => 'array',
            'colors' => 'array',
            'themes' => 'array',
            'events' => 'array',
            'images' => 'array',
            'rating' => 'float', 'review_count' => 'integer',
            'delivery' => 'boolean',
            'pickup' => 'boolean',
            'handling_fee' => 'float',
            'allow_overnight' => 'boolean', 'hidden_at' => 'datetime', 'written_off' => 'integer', 'max_hours' => 'float',
            'replacement_value' => 'float',
            'min_hours' => 'float', 'buffer_min' => 'integer',
            'weight_kg' => 'float', 'length_cm' => 'float', 'width_cm' => 'float', 'height_cm' => 'float',
            'perishable' => 'boolean',
            'lead_days' => 'integer',
            'shelf_life_days' => 'integer',
            'low_stock_at' => 'integer',
        ];
    }

    /** Units a sale item can be sold in. Rental items use "x / day" units. */
    public const SALE_UNITS = ['pc', 'set', 'bundle', 'bouquet', 'pack', 'box', 'bag', 'kg', 'sack', 'tray'];

    /** Categories whose new listings default to "For sale". */
    public const SALE_CATEGORIES = ['catering', 'flowers', 'favors', 'balloons'];

    /** Consumable: sold once, never returned, stock is not date-based. */
    public function isSale(): bool
    {
        return $this->listing_type === 'sale';
    }

    /** Rented by the hour: booked for a start–end time on one date, priced per hour. */
    public function isHourly(): bool
    {
        return ! $this->isSale() && $this->rental_mode === 'hour';
    }

    /** For sale items `total_stock` is the stock on hand. */
    public function stockOnHand(): int
    {
        return (int) $this->total_stock;
    }

    public function isLowStock(): bool
    {
        return $this->isSale() && $this->low_stock_at !== null && $this->stockOnHand() <= $this->low_stock_at;
    }

    /** "per day" for rentals, "per pc" for sale items. */
    public function priceUnitLabel(): string
    {
        return $this->isSale() ? 'per '.$this->unitWord() : 'per '.$this->unitWord().' / day';
    }

    public function movements(): HasMany { return $this->hasMany(StockMovement::class)->latest('id'); }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function holds(): HasMany { return $this->hasMany(Hold::class); }
    public function locationStock(): HasMany { return $this->hasMany(LocationStock::class); }

    /** [inventory_location_id => qty] (the reference stockByLocation). */
    public function stockByLocation(): array { return $this->locationStock->pluck('qty', 'inventory_location_id')->map(fn ($q) => (int) $q)->all(); }
    public function reviews(): HasMany { return $this->hasMany(Review::class); }

    /** Published listing from an approved supplier. */
    public function scopeLive($q)
    {
        return $q->where('status', 'active')->whereHas('supplier', fn ($s) => $s->where('status', 'approved'));
    }

    public function basePrice(): float { return Pricing::basePrice($this); }
    public function lowestPrice(): float { return Pricing::lowestPrice($this); }
    public function unitWord(): string { return trim(explode('/', $this->unit)[0]); }

    public function isNew(): bool
    {
        return $this->created_at && $this->created_at->diffInDays(now()) <= 14;
    }
}
