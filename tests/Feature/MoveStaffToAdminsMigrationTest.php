<?php

namespace Tests\Feature;

use App\Enums\ListingStatus;
use App\Models\Admin;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MoveStaffToAdminsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_users_become_admin_accounts_and_keep_their_reviews(): void
    {
        // roll back to just before this migration, including the ones that came after it
        $step = DB::table('migrations')->where('migration', '>=', '2026_10_31_105518_move_staff_to_admins_table')->count();
        $this->artisan('migrate:rollback', ['--step' => $step, '--force' => true])->assertSuccessful();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // the old setup: staff roles on website accounts
        $moderator = User::factory()->create(['email' => 'mod@metayaar.test', 'password' => 'mod-password']);
        $moderator->assignRole(Role::findOrCreate('moderator', 'web'));
        $moderator->assignRole(Role::findOrCreate('developer', 'web'));
        $moderator->createToken('panel', ['role:moderator']);
        $moderator->createToken('website', ['role:developer']);
        $player = User::factory()->create();
        $player->assignRole(Role::findOrCreate('player', 'web'));
        $listing = Listing::factory()->status(ListingStatus::Published)->create(['reviewed_by' => $moderator->id]);

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::firstWhere('email', 'mod@metayaar.test');
        $this->assertSame($moderator->password, $admin->password);
        $this->assertSame(['moderator'], $admin->getRoleNames()->all());
        $this->assertTrue($admin->hasPermissionTo('listings.review'));
        $this->assertSame($admin->id, $listing->fresh()->reviewed_by);

        $this->assertSame(['developer'], $moderator->fresh()->getRoleNames()->all());
        $this->assertSame([['role:developer']], $moderator->tokens()->pluck('abilities')->all());
        $this->assertSame(['player'], $player->fresh()->getRoleNames()->all());
        $this->assertSame(0, DB::table('roles')->where('guard_name', 'web')->where('name', 'moderator')->count());
    }
}
