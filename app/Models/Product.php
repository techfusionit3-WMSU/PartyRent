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
            'rating' => 'float',
            'delivery' => 'boolean',
            'pickup' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
    public function holds(): HasMany { return $this->hasMany(Hold::class); }
    public function reviews(): HasMany { return $this->hasMany(Review::class); }

    /** Published listing from an approved supplier. */
    public function scopeLive($q)
    {
        return $q->where('status', 'published')->whereHas('supplier', fn ($s) => $s->where('status', 'approved'));
    }

    public function basePrice(): float { return Pricing::basePrice($this); }
    public function lowestPrice(): float { return Pricing::lowestPrice($this); }
    public function unitWord(): string { return trim(explode('/', $this->unit)[0]); }

    public function isNew(): bool
    {
        return $this->created_at && $this->created_at->diffInDays(now()) <= 14;
    }
}
