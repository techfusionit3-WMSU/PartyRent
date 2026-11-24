<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier's branch: one physical place it rents from (shop, warehouse). The supplier is the business with one
 * public store; each branch keeps its own stock (location_stock; the product total is the sum, never combined at
 * checkout) and is the delivery origin of the rentals it fulfils (rental_requests.branch_id).
 */
class InventoryLocation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean', 'is_main' => 'boolean', 'lat' => 'float', 'lng' => 'float', 'address_parts' => 'array'];

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function stock(): HasMany { return $this->hasMany(LocationStock::class); }
    public function rentals(): HasMany { return $this->hasMany(RentalRequest::class, 'branch_id'); }

    public function scopeActive(Builder $q): Builder { return $q->where('active', true); }

    /** [lat, lng] of the branch: its pin, else the centre of its city; null when neither is known. */
    public function point(): ?array
    {
        if ($this->lat !== null && $this->lng !== null) {
            return [(float) $this->lat, (float) $this->lng];
        }
        $c = $this->city_code ? \App\Domain\Locations::get($this->city_code) : null;

        return $c && $c['lat'] !== null ? [(float) $c['lat'], (float) $c['lng']] : null;
    }

    public function hasPin(): bool { return $this->lat !== null && $this->lng !== null; }

    public function cityName(): string { return $this->city_code ? \App\Domain\Locations::label($this->city_code) : ''; }
}
