<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\Download;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DownloadStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('apks');
        $this->seed(RoleSeeder::class);
    }

    public function test_each_download_is_logged_with_user_source_and_client(): void
    {
        $listing = $this->listingWithApk();
        $member = User::factory()->create()->assignRole(Role::Member->value);
        Sanctum::actingAs($member);

        // proxied by the website from the headset app
        $this->get(route('v1.apps.download', $listing), [
            'X-Client-IP' => '5.160.10.20',
            'X-Client-User-Agent' => 'Mozilla/5.0 (Linux; Android 12; Quest 3) MetayaarQuest/0.1.0',
        ])->assertOk();
        // straight from a browser
        $this->get(route('v1.apps.download', $listing), ['User-Agent' => 'Mozilla/5.0 Firefox/130'])->assertOk();

        $this->assertDatabaseHas('downloads', [
            'listing_id' => $listing->id,
            'user_id' => $member->id,
            'source' => 'headset',
            'ip_address' => '5.160.10.20',
            'version_name' => $listing->version_name,
        ]);
        $this->assertDatabaseHas('downloads', ['listing_id' => $listing->id, 'source' => 'web']);
    }

    public function test_refused_downloads_are_not_logged(): void
    {
        $listing = $this->listingWithApk(['price' => 49000]);
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Member->value));

        $this->getJson(route('v1.apps.download', $listing))->assertPaymentRequired();

        $this->assertDatabaseCount('downloads', 0);
    }

    public function test_developer_sees_statistics_and_names_of_their_own_app(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        $jane = User::factory()->create(['name' => 'Jane']);
        Download::factory()->for($listing)->for($jane)->create(['source' => 'desktop']);
        Download::factory()->for($listing)->for($jane)->create(['source' => 'headset', 'created_at' => now()->subDays(3)]);
        Download::factory()->for($listing)->create(['source' => 'headset', 'created_at' => now()->subDays(60)]);
        Download::factory()->create(); // another app

        Sanctum::actingAs($listing->developer->assignRole(Role::Developer->value));

        $response = $this->getJson(route('v1.developer.apps.downloads', ['listing' => $listing, 'days' => 30]))
            ->assertOk()
            ->assertJsonPath('stats.total', 3)
            ->assertJsonPath('stats.unique_users', 2)
            ->assertJsonPath('stats.period_total', 2)
            ->assertJsonPath('stats.period_unique_users', 1)
            ->assertJsonPath('stats.previous_period_total', 0)
            ->assertJsonPath('stats.by_source', ['web' => 0, 'desktop' => 1, 'headset' => 1])
            ->assertJsonCount(30, 'stats.daily')
            ->assertJsonPath('stats.daily.29', ['date' => now()->toDateString(), 'total' => 1])
            ->assertJsonCount(3, 'data');

        $janes = collect($response->json('data'))->where('user.name', 'Jane');
        $this->assertCount(2, $janes);
        // developers don't get downloaders' emails, IPs or user agents
        $this->assertArrayNotHasKey('email', $janes->first()['user']);
        $this->assertArrayNotHasKey('ip_address', $janes->first());
    }

    public function test_developer_cannot_see_another_developers_statistics(): void
    {
        $listing = Listing::factory()->create();
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Developer->value));

        $this->getJson(route('v1.developer.apps.downloads', $listing))->assertForbidden();
    }

    public function test_developer_app_list_includes_download_counts(): void
    {
        $listing = Listing::factory()->create();
        Download::factory()->count(2)->for($listing)->create();
        Sanctum::actingAs($listing->developer->assignRole(Role::Developer->value));

        $this->getJson(route('v1.developer.apps.index'))->assertOk()->assertJsonPath('data.0.downloads_count', 2);
    }

    public function test_admin_sees_the_full_log_and_top_apps(): void
    {
        $popular = Listing::factory()->status(ListingStatus::Published)->create(['title' => 'Popular']);
        $quiet = Listing::factory()->status(ListingStatus::Published)->create(['title' => 'Quiet']);
        $jane = User::factory()->create(['email' => 'jane@example.com']);
        Download::factory()->count(3)->for($popular)->create();
        Download::factory()->for($quiet)->for($jane)->create(['ip_address' => '5.160.10.20', 'source' => 'web']);
        Download::factory()->for($quiet)->create(['created_at' => now()->subDays(100)]);
        $this->actingAsStaff(StaffRole::Admin);

        $this->getJson(route('v1.admin.statistics.downloads', ['days' => 30]))
            ->assertOk()
            ->assertJsonPath('data.stats.total', 5)
            ->assertJsonPath('data.stats.period_total', 4)
            ->assertJsonPath('data.top_listings.0.title', 'Popular')
            ->assertJsonPath('data.top_listings.0.period_downloads', 3)
            ->assertJsonPath('data.top_listings.1.title', 'Quiet')
            ->assertJsonPath('data.top_listings.1.downloads_count', 2);

        $this->getJson(route('v1.admin.downloads.index', ['user_id' => $jane->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.email', 'jane@example.com')
            ->assertJsonPath('data.0.ip_address', '5.160.10.20')
            ->assertJsonPath('data.0.listing.title', 'Quiet');

        $this->getJson(route('v1.admin.listings.downloads', $quiet))
            ->assertOk()
            ->assertJsonPath('listing.title', 'Quiet')
            ->assertJsonPath('stats.total', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_statistics_need_the_permission(): void
    {
        $this->actingAsStaff(StaffRole::Curator);

        $this->getJson(route('v1.admin.statistics.downloads'))->assertForbidden();
        $this->getJson(route('v1.admin.downloads.index'))->assertForbidden();
    }

    public function test_period_must_be_one_of_the_offered_ones(): void
    {
        $this->actingAsStaff(StaffRole::Admin);

        $this->getJson(route('v1.admin.statistics.downloads', ['days' => 5000]))->assertUnprocessable();
    }

    private function actingAsStaff(StaffRole $role): Admin
    {
        $admin = Admin::factory()->create()->assignRole($role->value);
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function listingWithApk(array $attributes = []): Listing
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create($attributes);
        $path = "listings/{$listing->id}/game.apk";
        Storage::disk('apks')->put($path, 'apk-bytes');
        $listing->attachApk($path, 'game.apk', 9, hash('sha256', 'apk-bytes'));

        return $listing;
    }
}
