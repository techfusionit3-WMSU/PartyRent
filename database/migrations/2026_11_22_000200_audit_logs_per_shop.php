<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tester feedback 2026-11-22: every change a shop's people make (who, what, when) is kept, and the owner sees it.
 * The audit log gets the shop it belongs to and the record it is about (rental code, item name...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('actor_id')->constrained()->nullOnDelete();
            $table->string('ref', 80)->nullable()->after('action');
            $table->index(['supplier_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['supplier_id', 'created_at']);
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('ref');
        });
    }
};
