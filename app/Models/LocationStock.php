<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Units of one product kept at one inventory location. */
class LocationStock extends Model
{
    protected $table = 'location_stock';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array { return ['qty' => 'integer']; }

    public function location(): BelongsTo { return $this->belongsTo(InventoryLocation::class, 'inventory_location_id'); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
