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
}
