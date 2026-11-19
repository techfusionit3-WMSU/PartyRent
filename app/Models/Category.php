<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'pending' => 'boolean'];
    }

    public function products(): HasMany { return $this->hasMany(Product::class); }

    public function gradient(): string
    {
        return 'linear-gradient(135deg,'.$this->grad_from.','.$this->grad_to.')';
    }
}
