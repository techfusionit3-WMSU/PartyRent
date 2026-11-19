<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business (organization) suppliers show who they are, not just what they rent: a logo photo (logo_path already
 * exists), a cover photo, a portfolio of past events, and what they offer (events catered, services, event size,
 * packages-from price) in business_profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->string('cover_path')->nullable()->after('logo_path');
            $t->json('portfolio')->nullable()->after('cover_path');
            $t->json('business_profile')->nullable()->after('portfolio');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->dropColumn(['cover_path', 'portfolio', 'business_profile']);
        });
    }
};
