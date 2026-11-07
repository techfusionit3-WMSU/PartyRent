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
            'delivery_fee' => 'float',
            'rating' => 'float',
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
