<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\StoreTab;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_fresh_database(): void
    {
        $this->seed();

        $this->assertTrue(Admin::firstWhere('email', 'admin@example.com')->hasPermissionTo('statistics.view'));
    }

    public function test_reseeding_adds_new_permissions_and_keeps_existing_data(): void
    {
        $this->seed();

        Permission::findByName('statistics.view', Admin::GUARD)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findByName('moderator', Admin::GUARD)->syncPermissions(['store.curate']);
        StoreTab::firstWhere('slug', 'free')->update(['label' => 'Edited by admin']);
        $staffCount = Admin::count();

        $this->seed();

        $this->assertTrue(Role::findByName('admin', Admin::GUARD)->hasPermissionTo('statistics.view'));
        $this->assertSame(['store.curate'], Role::findByName('moderator', Admin::GUARD)->permissions->pluck('name')->all());
        $this->assertSame('Edited by admin', StoreTab::firstWhere('slug', 'free')->label);
        $this->assertSame($staffCount, Admin::count());
    }

    public function test_a_permission_added_in_a_release_reaches_existing_starting_roles(): void
    {
        $this->seed();

        // a server from before comment moderation existed: the permission is new, and an admin had
        // taken listings.review away from moderators
        Permission::findByName('comments.moderate', Admin::GUARD)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findByName('moderator', Admin::GUARD)->syncPermissions(['store.curate']);

        $this->seed();

        $this->assertEqualsCanonicalizing(
            ['store.curate', 'comments.moderate'],
            Role::findByName('moderator', Admin::GUARD)->permissions->pluck('name')->all(),
        );
        $this->assertFalse(Role::findByName('curator', Admin::GUARD)->hasPermissionTo('comments.moderate'));
    }
}
