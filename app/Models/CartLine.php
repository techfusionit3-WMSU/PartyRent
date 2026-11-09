<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['days' => 'array', 'sel' => 'array'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    /** The line in the shape the domain services use. `sel` = package choices / add-ons / guests. */
    public function toLine(): array
    {
        $l = ['id' => 'c'.$this->id, 'type' => $this->type, 'ref_id' => (int) $this->ref_id, 'days' => $this->days ?? []];
        if ($this->type === 'package') {
            $l['sel'] = $this->sel ?? [];
        }

        return $l;
    }
}
