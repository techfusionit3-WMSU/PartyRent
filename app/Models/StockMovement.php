<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stock on hand changes for sale items: sold (released to a customer), restock, adjust. */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function rentalRequest(): BelongsTo { return $this->belongsTo(RentalRequest::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function fromLocation(): BelongsTo { return $this->belongsTo(InventoryLocation::class, 'inventory_location_id'); }
    public function toLocation(): BelongsTo { return $this->belongsTo(InventoryLocation::class, 'to_location_id'); }
}
