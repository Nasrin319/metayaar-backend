<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // set by staff in the admin panel: a disabled account cannot sign in, and its tokens are revoked
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('mobile_verified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
