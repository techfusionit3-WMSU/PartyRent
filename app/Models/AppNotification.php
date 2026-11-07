<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    public const UPDATED_AT = null;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_by' => 'array'];
    }

    public function isReadBy(int $userId): bool
    {
        return in_array($userId, $this->read_by ?? [], true);
    }
}
