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
        // values admins change in the panel; missing keys fall back to Setting::DEFAULTS
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        // codes sent to an email or mobile since it last signed in, and how long it is locked out
        Schema::create('otp_lockouts', function (Blueprint $table) {
            $table->id();
            $table->string('channel');
            $table->string('destination');
            $table->unsignedInteger('sends')->default(0);
            $table->timestamp('window_started_at')->nullable();
            $table->timestamp('locked_until')->nullable()->index();
            $table->timestamps();

            $table->unique(['channel', 'destination']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_lockouts');
        Schema::dropIfExists('settings');
    }
};
