<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock one branch lends to another for a combined rental (App\Domain\Transfers).
 * planned → sent → received → returned (sent back) or kept (stays at the receiving branch); cancelled.
 */
class RentalTransfer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'sent_at' => 'datetime', 'received_at' => 'datetime', 'closed_at' => 'datetime', 'qty' => 'integer'];
    }

    public const STATUS = [
        'planned' => ['To send', 'b-gold'], 'sent' => ['On the way', 'b-sky'], 'received' => ['Received', 'b-green'],
        'returned' => ['Sent back', 'b-gray'], 'kept' => ['Kept at receiving branch', 'b-gray'], 'cancelled' => ['Cancelled', 'b-gray'],
    ];

    /** Still needs someone to do something. */
    public const OPEN = ['planned', 'sent', 'received'];

    public function rentalRequest(): BelongsTo { return $this->belongsTo(RentalRequest::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function from(): BelongsTo { return $this->belongsTo(InventoryLocation::class, 'from_location_id'); }
    public function to(): BelongsTo { return $this->belongsTo(InventoryLocation::class, 'to_location_id'); }
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sent_by'); }
    public function receiver(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
    public function closer(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }

    public function statusLabel(): string { return self::STATUS[$this->status][0] ?? ucfirst($this->status); }
    public function badge(): string { return self::STATUS[$this->status][1] ?? 'b-gray'; }
}
