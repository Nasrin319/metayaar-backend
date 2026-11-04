<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Developers asking to be paid from their wallet. Amounts are in toman. The bank details are copied
     * from the payout account, so a later change doesn't alter where an open request gets paid.
     */
    public function up(): void
    {
        Schema::create('payout_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('status')->default('pending');
            $table->string('holder_name');
            $table->string('bank_name');
            $table->text('sheba');
            $table->text('card_number')->nullable();
            // the bank's tracking code once paid, why it was turned down otherwise
            $table->string('reference')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_requests');
    }
};
