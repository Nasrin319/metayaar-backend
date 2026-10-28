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
            $table->unsignedInteger('version_code')->nullable()->after('package_name');
            $table->string('version_name', 50)->nullable()->after('version_code');
            $table->boolean('seated')->default(false)->after('category');
            $table->string('comfort', 20)->default('comfortable')->after('seated');
            $table->json('supported_devices')->nullable()->after('comfort');
            $table->json('tags')->nullable()->after('supported_devices');
            $table->json('links')->nullable()->after('tags');
            $table->string('trailer_url', 2048)->nullable()->after('screenshot_paths');
            $table->string('title_image_path')->nullable()->after('icon_from_apk');
            $table->boolean('early_access')->default(false)->after('status');
            $table->json('submission_answers')->nullable()->after('submitted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn([
                'version_code', 'version_name', 'seated', 'comfort', 'supported_devices', 'tags', 'links',
                'trailer_url', 'title_image_path', 'early_access', 'submission_answers',
            ]);
        });
    }
};
