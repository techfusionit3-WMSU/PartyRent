<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Request Rental" on a listing checks out exactly that item: it is stored as a direct cart line that stays out of
 * the Rental List (not merged with, counted with, or checked against the list's lines).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_lines', fn (Blueprint $t) => $t->boolean('direct')->default(false));
    }

    public function down(): void
    {
        Schema::table('cart_lines', fn (Blueprint $t) => $t->dropColumn('direct'));
    }
};
