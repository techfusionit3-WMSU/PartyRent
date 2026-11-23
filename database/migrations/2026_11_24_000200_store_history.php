<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store History: every audit row says what kind of action it was, which part of the store it touched,
 * the person's role in the shop, and the values before and after (field => [old, new]).
 * Rows are never edited or deleted (App\Models\AuditLog refuses it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->string('type', 12)->nullable()->after('role');      // created | updated | deleted | approved | rejected | cancelled | completed | payment | sent | system
            $t->string('category', 20)->nullable()->after('type'); // rentals | products | packages | inventory | pricing | payments | payouts | settings | team | cases | reviews | events
            $t->string('role_label', 60)->nullable()->after('role'); // Owner, Cashier, Front Desk + Field Crew, PartyRent (automatic)
            $t->json('changes')->nullable()->after('details');
            $t->index(['supplier_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->dropIndex(['supplier_id', 'category']);
            $t->dropColumn(['type', 'category', 'role_label', 'changes']);
        });
    }
};
