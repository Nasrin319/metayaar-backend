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
            $table->string('apk_path')->nullable()->after('external_url');
            $table->string('apk_original_name')->nullable()->after('apk_path');
            $table->unsignedBigInteger('apk_size')->nullable()->after('apk_original_name');
            $table->char('apk_sha256', 64)->nullable()->after('apk_size');
            $table->timestamp('apk_uploaded_at')->nullable()->after('apk_sha256');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['apk_path', 'apk_original_name', 'apk_size', 'apk_sha256', 'apk_uploaded_at']);
        });
    }
};
