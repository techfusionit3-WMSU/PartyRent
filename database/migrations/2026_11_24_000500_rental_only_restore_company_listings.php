<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PartyRent rents decorations and party items only (event-company module off). When companies were made event
 * companies, their item listings and packages were hidden ("kept, not deleted"); as rental shops they show again.
 * Skipped while the event-company module is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('partyrent.event_companies')) {
            return;
        }
        $orgs = DB::table('suppliers')->where('type', 'organization')->where('status', 'approved')->pluck('id');
        if ($orgs->isEmpty()) {
            return;
        }
        DB::table('products')->whereIn('supplier_id', $orgs)->where('status', 'inactive')->update(['status' => 'active']);
        DB::table('packages')->whereIn('supplier_id', $orgs)->where('active', false)->where('is_draft', false)->update(['active' => true]);
    }

    public function down(): void
    {
        // listings stay visible
    }
};
