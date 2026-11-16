<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier's warehouse / branch. Product stock is split across locations (product total = sum). */
class InventoryLocation extends Model
{
    protected $guarded = ['id'];

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function stock(): HasMany { return $this->hasMany(LocationStock::class); }
}
