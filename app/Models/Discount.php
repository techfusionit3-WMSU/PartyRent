<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A shop discount (change list C11). See App\Domain\Discounts. */
class Discount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'float', 'product_ids' => 'array', 'min_qty' => 'integer', 'active' => 'boolean', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    /** "10% off" / "₱20 off per piece" */
    public function label(): string
    {
        return $this->kind === 'percent' ? rtrim(rtrim(number_format($this->value, 2), '0'), '.').'% off' : money_short($this->value).' off per piece';
    }

    /** Scheduled / Running / Ended / Off on a date. */
    public function state(?string $at = null): string
    {
        $at ??= today_iso();
        if (! $this->active) {
            return 'off';
        }
        if ($this->starts_on && $this->starts_on->format('Y-m-d') > $at) {
            return 'scheduled';
        }

        return $this->ends_on && $this->ends_on->format('Y-m-d') < $at ? 'ended' : 'running';
    }

    public function covers(Product $p): bool
    {
        return $this->applies_to === 'all' || in_array($p->id, array_map('intval', $this->product_ids ?? []), true);
    }
}
