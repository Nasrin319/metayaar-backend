<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An email without an account now waits for staff. Lists saved before this rule follow it too.
     */
    public function up(): void
    {
        Schema::table('listing_invites', function (Blueprint $table) {
            $table->string('status')->default('active')->index();
        });

        DB::table('listing_invites')
            ->whereNotIn('email', DB::table('users')->whereNotNull('email')->select('email'))
            ->update(['status' => 'pending']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listing_invites', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
