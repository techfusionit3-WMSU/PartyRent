<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Cash on Delivery" is now plain "Cash": the customer hands cash to the shop (on delivery, at pickup or at the
 * shop) and the shop records it. Stored method key cod -> cash on shops, requests and payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rental_requests')->where('method', 'cod')->update(['method' => 'cash']);
        DB::table('payments')->where('method', 'cod')->update(['method' => 'cash']);
        foreach (DB::table('suppliers')->select('id', 'methods')->get() as $s) {
            $m = json_decode((string) $s->methods, true);
            if (is_array($m) && in_array('cod', $m, true)) {
                $m = array_values(array_unique(array_map(fn ($x) => $x === 'cod' ? 'cash' : $x, $m)));
                DB::table('suppliers')->where('id', $s->id)->update(['methods' => json_encode($m)]);
            }
        }
    }

    public function down(): void
    {
        DB::table('rental_requests')->where('method', 'cash')->update(['method' => 'cod']);
        foreach (DB::table('suppliers')->select('id', 'methods')->get() as $s) {
            $m = json_decode((string) $s->methods, true);
            if (is_array($m) && in_array('cash', $m, true)) {
                DB::table('suppliers')->where('id', $s->id)->update(['methods' => json_encode(array_map(fn ($x) => $x === 'cash' ? 'cod' : $x, $m))]);
            }
        }
    }
};
