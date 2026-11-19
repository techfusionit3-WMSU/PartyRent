<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suppliers can type a category that does not exist yet. It is saved as a pending (inactive) category for an
 * admin to approve, merge into an existing one, or reject. Meanwhile the product sits in "Other Party Supplies"
 * (category_id = other) and remembers the requested one in requested_category_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $t) {
            $t->boolean('pending')->default(false)->after('active');
            $t->foreignId('requested_by')->nullable()->after('pending')->constrained('users')->nullOnDelete();
        });
        Schema::table('products', function (Blueprint $t) {
            $t->string('requested_category_id', 40)->nullable()->after('category_id');
            $t->foreign('requested_category_id')->references('id')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropForeign(['requested_category_id']);
            $t->dropColumn('requested_category_id');
        });
        Schema::table('categories', function (Blueprint $t) {
            $t->dropConstrainedForeignId('requested_by');
            $t->dropColumn('pending');
        });
    }
};
