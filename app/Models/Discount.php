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
        return ['value' => 'float', 'min_amount' => 'float', 'product_ids' => 'array', 'min_qty' => 'integer', 'active' => 'boolean', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }

    /** What the discount takes off, how it is shown in the form. */
    public const KINDS = [
        'percent' => ['% off each item', 'Each covered item\'s price goes down by this percent.'],
        'amount' => ['₱ off per piece', 'This many pesos come off every piece (per day for rentals).'],
        'order_percent' => ['% off the whole order', 'The covered items\' total in one order goes down by this percent.'],
        'order_amount' => ['₱ off the whole order', 'This many pesos come off the order once (not per piece).'],
    ];

    public function isOrderLevel(): bool
    {
        return str_starts_with((string) $this->kind, 'order_');
    }

    /** "10% off each item" / "₱20 off per piece" / "10% off the whole order" / "₱500 off the order" */
    public function label(): string
    {
        $pct = rtrim(rtrim(number_format($this->value, 2), '0'), '.').'%';

        return match ($this->kind) {
            'percent' => $pct.' off',
            'order_percent' => $pct.' off the whole order',
            'order_amount' => money_short($this->value).' off the order',
            default => money_short($this->value).' off per piece',
        }.($this->isOrderLevel() && $this->min_amount ? ' of '.money_short($this->min_amount).'+' : '');
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
