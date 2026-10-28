<?php

namespace Tests\Feature\Api\V1\Developer;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Enums\Role;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->developer = User::factory()->create()->assignRole(Role::Developer->value);
        Sanctum::actingAs($this->developer);
    }

    public function test_developer_lists_only_their_own_listings_filtered_by_type(): void
    {
        Listing::factory()->for($this->developer, 'developer')->create(['title' => 'Mine APK']);
        Listing::factory()->for($this->developer, 'developer')->external()->create(['title' => 'Mine WebXR']);
        Listing::factory()->create(['title' => 'Someone else']);

        $this->getJson(route('v1.developer.apps.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson(route('v1.developer.apps.index', ['type' => 'webxr']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine WebXR');
    }

    public function test_developer_can_create_an_apk_listing_as_draft(): void
    {
        $this->postJson(route('v1.developer.apps.store'), [
            'title' => 'Neon Blade',
            'type' => 'apk',
            'package_name' => 'com.studio.neonblade',
            'price' => 49000,
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'neon-blade')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.type', 'apk');

        $this->assertTrue($this->developer->listings()->where('title', 'Neon Blade')->exists());
    }

    public function test_listing_type_decides_required_fields(): void
    {
        // an APK draft can be created from just a name and filled in later
        $draft = $this->postJson(route('v1.developer.apps.store'), ['title' => 'No package', 'type' => 'apk', 'tagline' => 'Soon'])
            ->assertCreated()
            ->assertJsonPath('data.package_name', null)
            ->json('data.id');
        $this->postJson(route('v1.developer.apps.submit', $draft))->assertJsonValidationErrors('package_name');

        $this->postJson(route('v1.developer.apps.store'), ['title' => 'No url', 'type' => 'app_lab'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('external_url');

        $this->postJson(route('v1.developer.apps.store'), ['title' => 'Bad type', 'type' => 'ios'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_developer_can_update_submit_and_delete_their_listing(): void
    {
        $listing = Listing::factory()->for($this->developer, 'developer')->create();

        $this->putJson(route('v1.developer.apps.update', $listing), [
            'title' => 'Renamed',
            'type' => 'webxr',
            'external_url' => 'https://example.com/xr',
        ])->assertOk()->assertJsonPath('data.title', 'Renamed');

        $this->postJson(route('v1.developer.apps.submit', $listing))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review');

        $this->putJson(route('v1.developer.apps.update', $listing), [
            'title' => 'Locked while in review',
            'type' => 'webxr',
            'external_url' => 'https://example.com/xr',
        ])->assertForbidden();

        $this->deleteJson(route('v1.developer.apps.destroy', $listing))->assertNoContent();
        $this->assertModelMissing($listing);
    }

    public function test_developer_cannot_touch_another_developers_listing(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Draft)->create(['type' => ListingType::Apk]);

        $this->getJson(route('v1.developer.apps.show', $listing))->assertForbidden();
        $this->postJson(route('v1.developer.apps.submit', $listing))->assertForbidden();
        $this->deleteJson(route('v1.developer.apps.destroy', $listing))->assertForbidden();
    }

    public function test_players_cannot_use_the_developer_api(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Player->value));

        $this->getJson(route('v1.developer.apps.index'))->assertForbidden();
    }

    public function test_developer_can_save_store_details(): void
    {
        $listing = Listing::factory()->for($this->developer, 'developer')->create();

        $this->putJson(route('v1.developer.apps.update', $listing), [
            'title' => $listing->title,
            'type' => 'apk',
            'version_code' => 12,
            'version_name' => '1.2.0',
            'seated' => true,
            'comfort' => 'intense',
            'supported_devices' => ['quest_3', 'pico_4'],
            'tags' => ['shooter', 'co-op'],
            'links' => [['type' => 'discord', 'url' => 'https://discord.gg/neon']],
            'trailer_url' => 'https://www.youtube.com/watch?v=abc',
            'early_access' => true,
        ])->assertOk()
            ->assertJsonPath('data.version_code', 12)
            ->assertJsonPath('data.comfort', 'intense')
            ->assertJsonPath('data.supported_devices', ['quest_3', 'pico_4'])
            ->assertJsonPath('data.links.0.type', 'discord')
            ->assertJsonPath('data.early_access', true);

        $this->putJson(route('v1.developer.apps.update', $listing), [
            'title' => $listing->title,
            'type' => 'apk',
            'supported_devices' => ['toaster'],
            'links' => [['type' => 'discord', 'url' => 'not a url']],
            'trailer_url' => 'https://example.com/video.mp4',
        ])->assertJsonValidationErrors(['supported_devices.0', 'links.0.url', 'trailer_url']);
    }

    public function test_submission_checklist_answers_are_stored(): void
    {
        $listing = Listing::factory()->for($this->developer, 'developer')->create();
        $listing->forceFill(['apk_path' => 'x.apk', 'apk_original_name' => 'x.apk', 'apk_size' => 1, 'apk_sha256' => str_repeat('a', 64)])->save();

        $this->postJson(route('v1.developer.apps.submit', $listing), ['answers' => ['is_port' => false, 'has_accounts' => true]])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.submission_answers.has_accounts', true);
    }

    public function test_developer_can_duplicate_a_listing_under_a_new_package_name(): void
    {
        $listing = Listing::factory()->for($this->developer, 'developer')->status(ListingStatus::Published)->create(['tags' => ['puzzle']]);

        $this->postJson(route('v1.developer.apps.duplicate', $listing), ['package_name' => 'com.copy.app'])
            ->assertCreated()
            ->assertJsonPath('data.package_name', 'com.copy.app')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.tags', ['puzzle'])
            ->assertJsonPath('data.apk', null);

        $this->assertSame(2, $this->developer->listings()->count());

        $other = Listing::factory()->create();
        $this->postJson(route('v1.developer.apps.duplicate', $other), ['package_name' => 'com.copy.two'])->assertForbidden();
    }
}
