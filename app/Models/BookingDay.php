<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One product, one day, one request line: the normalised form of rental_requests.lines used for locked stock checks. */
class BookingDay extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array { return ['qty' => 'integer']; }
}
