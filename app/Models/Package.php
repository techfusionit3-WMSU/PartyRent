<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Package extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'events' => 'array', 'images' => 'array', 'active' => 'boolean', 'price' => 'float'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    public function scopeLive($q)
    {
        return $q->where('active', true)->whereHas('supplier', fn ($s) => $s->where('status', 'approved'));
    }
}
