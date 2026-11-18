<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One chat message between the customer and the shop on a rental request. */
class RequestMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function rentalRequest(): BelongsTo { return $this->belongsTo(RentalRequest::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
