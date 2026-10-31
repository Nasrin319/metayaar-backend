<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\Role;
use App\Enums\StoreTabRule;
use App\Models\StoreTab;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StoreTabTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_creates_updates_and_deletes_tabs(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Admin->value));

        $id = $this->postJson(route('v1.admin.store-tabs.store'), [
            'label' => 'تیراندازی',
            'rule' => 'tag',
            'value' => 'shooter',
            'group' => 3,
            'position' => 2,
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'shooter')
            ->json('data.id');

        $this->putJson(route('v1.admin.store-tabs.update', $id), [
            'label' => 'رایگان',
            'slug' => 'Free Games',
            'rule' => 'free',
            'value' => 'ignored',
            'group' => 2,
            'position' => 0,
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'free-games')
            ->assertJsonPath('data.value', null)
            ->assertJsonPath('data.is_active', false);

        $this->getJson(route('v1.admin.store-tabs.index'))->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson(route('v1.admin.store-tabs.destroy', $id))->assertNoContent();
        $this->assertDatabaseCount('store_tabs', 0);
    }

    public function test_category_and_tag_tabs_need_a_value_and_slugs_are_unique(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Admin->value));
        StoreTab::factory()->create(['slug' => 'free']);

        $this->postJson(route('v1.admin.store-tabs.store'), [
            'label' => 'Category',
            'slug' => 'free',
            'rule' => 'category',
            'group' => 1,
            'position' => 0,
            'is_active' => true,
        ])->assertJsonValidationErrors(['value', 'slug']);

        // without a slug, a clashing derived slug gets a suffix instead of failing
        $this->postJson(route('v1.admin.store-tabs.store'), [
            'label' => 'Free again',
            'rule' => 'free',
            'group' => 1,
            'position' => 0,
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.slug', fn (string $slug) => str_starts_with($slug, 'free-'));
    }

    public function test_moderators_cannot_manage_tabs(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Moderator->value));
        $tab = StoreTab::factory()->create();

        $this->getJson(route('v1.admin.store-tabs.index'))->assertForbidden();
        $this->deleteJson(route('v1.admin.store-tabs.destroy', $tab))->assertForbidden();
    }

    public function test_store_lists_only_active_tabs_in_order(): void
    {
        StoreTab::factory()->create(['label' => 'Genre', 'group' => 2, 'position' => 0]);
        StoreTab::factory()->create(['label' => 'All', 'group' => 1, 'position' => 5]);
        StoreTab::factory()->create(['label' => 'Hidden', 'is_active' => false]);
        StoreTab::factory()->matching(StoreTabRule::Free)->create(['label' => 'Free', 'group' => 1, 'position' => 1]);

        $this->getJson(route('v1.store-tabs.index'))
            ->assertOk()
            ->assertJsonPath('data.*.label', ['Free', 'All', 'Genre']);
    }
}
