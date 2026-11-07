<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Complaint extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['updates' => 'array'];
    }

    public function reporter(): BelongsTo { return $this->belongsTo(User::class, 'reporter_id'); }
    public function againstSupplier(): BelongsTo { return $this->belongsTo(Supplier::class, 'against_supplier_id'); }
    public function rentalRequest(): BelongsTo { return $this->belongsTo(RentalRequest::class); }
}
