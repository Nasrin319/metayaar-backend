<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The store isn't only games, so the regular website role is "member" now. Accounts keep their
     * role, and tokens that are already signed in keep working.
     */
    public function up(): void
    {
        $this->rename('player', 'member');
    }

    public function down(): void
    {
        $this->rename('member', 'player');
    }

    private function rename(string $from, string $to): void
    {
        DB::table('roles')->where(['name' => $from, 'guard_name' => 'web'])->update(['name' => $to]);
        DB::table('personal_access_tokens')
            ->where('abilities', json_encode(["role:{$from}"]))
            ->update(['abilities' => json_encode(["role:{$to}"])]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
