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
        Schema::table('listings', function (Blueprint $table) {
            $table->string('icon_path')->nullable()->after('apk_uploaded_at');
            $table->string('cover_path')->nullable()->after('icon_path');
            $table->json('screenshot_paths')->nullable()->after('cover_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['icon_path', 'cover_path', 'screenshot_paths']);
        });
    }
};
