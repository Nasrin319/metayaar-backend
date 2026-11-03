<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sign-in tokens now carry their own expiry (the session_hours admin setting) instead of relying on
     * SANCTUM_EXPIRATION. Give older tokens the 12 hours that applied when they were issued, so none of
     * them stays valid forever.
     */
    public function up(): void
    {
        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->orderBy('id')
            ->chunkById(500, function ($tokens): void {
                foreach ($tokens as $token) {
                    DB::table('personal_access_tokens')
                        ->where('id', $token->id)
                        ->update(['expires_at' => Carbon::parse($token->created_at)->addHours(12)]);
                }
            });
    }

    /**
     * Nothing to undo: the expiry dates are harmless if the old configuration comes back.
     */
    public function down(): void {}
};
