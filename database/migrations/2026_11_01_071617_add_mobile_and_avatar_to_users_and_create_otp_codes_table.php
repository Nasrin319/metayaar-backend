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
        Schema::table('users', function (Blueprint $table) {
            // only stored once verified with a code, so every saved mobile belongs to its account
            $table->string('mobile', 11)->nullable()->unique()->after('email_verified_at');
            $table->timestamp('mobile_verified_at')->nullable()->after('mobile');
            $table->string('avatar_path')->nullable()->after('mobile_verified_at');
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('channel');
            $table->string('destination');
            $table->string('purpose');
            $table->string('code');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['channel', 'destination', 'purpose']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_codes');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['mobile']);
            $table->dropColumn(['mobile', 'mobile_verified_at', 'avatar_path']);
        });
    }
};
