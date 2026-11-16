<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One entry of a shortfall case: report, proposal, response, message, escalation or applied change. */
class CaseEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    public function shortfallCase(): BelongsTo { return $this->belongsTo(ShortfallCase::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
