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
            $table->boolean('is_private')->default(false)->index();
        });

        Schema::create('listing_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            // an address, not a user: people can be invited before they have an account
            $table->string('email');
            $table->unique(['listing_id', 'email']);
            $table->index('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_invites');

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    }
};
