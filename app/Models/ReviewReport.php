<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewReport extends Model
{
    public const REASONS = [
        'fake' => 'Fake or not from a real rental',
        'offensive' => 'Offensive or abusive language',
        'spam' => 'Spam or advertising',
        'wrong_item' => 'About a different item or shop',
        'private_info' => 'Shares private information',
        'other' => 'Something else',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function review(): BelongsTo { return $this->belongsTo(Review::class); }
    public function reporter(): BelongsTo { return $this->belongsTo(User::class, 'reporter_id'); }

    public function reasonLabel(): string { return self::REASONS[$this->reason] ?? $this->reason; }
}
