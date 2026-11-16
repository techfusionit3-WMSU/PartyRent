<?php

namespace App\Models;

use App\Domain\Packages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Supplier-built package. The real definition is `components` + `pricing` (see App\Domain\Packages).
 * `items` (included products at their base quantity) and `price` (headline price for one package,
 * one day, default options) are kept in sync on save for listings, search and the AI planner.
 */
class Package extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'items' => 'array', 'events' => 'array', 'images' => 'array', 'components' => 'array', 'pricing' => 'array', 'stages' => 'array', 'rental_events' => 'array', 'hidden_at' => 'datetime', 'min_hours' => 'float',
            'active' => 'boolean', 'is_draft' => 'boolean', 'price' => 'float', 'min_guests' => 'integer', 'max_guests' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Package $k) {
            if ($k->components) {
                $def = Packages::def($k);
                $k->items = Packages::summaryItems($def);
                $k->price = Packages::headlinePrice($def);
            }
        });
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function reviews(): HasMany { return $this->hasMany(Review::class); }

    public function scopeLive($q)
    {
        return $q->where('active', true)->where('is_draft', false)->whereHas('supplier', fn ($s) => $s->where('status', 'approved'));
    }

    public function statusLabel(): string
    {
        return $this->is_draft ? 'Draft' : ($this->active ? 'Published' : 'Inactive');
    }
}
