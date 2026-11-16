<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Units moved between two inventory locations of one supplier. */
class StockTransfer extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array { return ['qty' => 'integer']; }

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function from(): BelongsTo { return $this->belongsTo(InventoryLocation::class, 'from_location_id'); }
    public function to(): BelongsTo { return $this->belongsTo(InventoryLocation::class, 'to_location_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
