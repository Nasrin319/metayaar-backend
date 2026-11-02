<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Amounts are in toman.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // the sum of the user's wallet_transactions, kept up to date by App\Support\Sales
            $table->bigInteger('wallet_balance')->default(0)->after('account_type');
        });

        Schema::table('listings', function (Blueprint $table) {
            // Metayaar's share of each sale, set by an admin; null uses the default_commission_percent setting
            $table->unsignedTinyInteger('commission_percent')->nullable()->after('price');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->unsignedTinyInteger('commission_percent')->default(0)->after('amount');
            $table->unsignedInteger('commission_amount')->default(0)->after('commission_percent');
            $table->unsignedInteger('developer_amount')->default(0)->after('commission_amount');
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            // positive credits the wallet, negative takes money out
            $table->bigInteger('amount');
            $table->bigInteger('balance_after');
            $table->foreignId('purchase_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn(['commission_percent', 'commission_amount', 'developer_amount']);
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('commission_percent');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wallet_balance');
        });
    }
};
