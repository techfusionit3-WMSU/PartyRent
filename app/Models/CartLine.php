<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['days' => 'array'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    /** The line in the shape the domain services use. */
    public function toLine(): array
    {
        return ['id' => 'c'.$this->id, 'type' => $this->type, 'ref_id' => (int) $this->ref_id, 'days' => $this->days ?? []];
    }
}
