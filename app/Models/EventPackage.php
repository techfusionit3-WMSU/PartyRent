<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A business's event package: priced per event, for a guest range, with what is included. */
class EventPackage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['events' => 'array', 'inclusions' => 'array', 'photos' => 'array', 'price' => 'float', 'active' => 'boolean', 'min_guests' => 'integer', 'max_guests' => 'integer'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    public function scopeLive($q)
    {
        return $q->where('active', true)->whereHas('supplier', fn ($s) => $s->where('status', 'approved'));
    }

    public function guestsLabel(): string
    {
        return match (true) {
            $this->min_guests && $this->max_guests => $this->min_guests.'–'.$this->max_guests.' guests',
            (bool) $this->max_guests => 'Up to '.$this->max_guests.' guests',
            (bool) $this->min_guests => $this->min_guests.'+ guests',
            default => 'Any size',
        };
    }

    public function cover(): ?string
    {
        $p = ($this->photos ?? [])[0] ?? null;

        return $p ? asset('storage/'.$p) : null;
    }
}
