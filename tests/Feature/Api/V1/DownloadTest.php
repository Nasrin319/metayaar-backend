<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Models\Listing;
use App\Models\Purchase;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('apks');
        $this->seed(RoleSeeder::class);
        $this->player = User::factory()->create()->assignRole(Role::Player->value);
    }

    public function test_guests_cannot_download(): void
    {
        $listing = $this->listingWithApk();

        $this->getJson(route('v1.apps.download', $listing))->assertUnauthorized();
    }

    public function test_signed_in_player_downloads_a_free_published_apk(): void
    {
        $listing = $this->listingWithApk();
        Sanctum::actingAs($this->player);

        $response = $this->get(route('v1.apps.download', $listing))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.android.package-archive')
            ->assertDownload("{$listing->slug}.apk");

        $this->assertSame('apk-bytes', $response->streamedContent());
    }

    public function test_paid_apk_requires_a_purchase(): void
    {
        $listing = $this->listingWithApk(['price' => 49000]);
        Sanctum::actingAs($this->player);

        $this->getJson(route('v1.apps.download', $listing))->assertPaymentRequired();

        Purchase::factory()->for($this->player)->for($listing)->create();

        $this->get(route('v1.apps.download', $listing))->assertOk();
    }

    public function test_developer_downloads_their_own_paid_apk_without_buying_it(): void
    {
        $listing = $this->listingWithApk(['price' => 49000]);
        Sanctum::actingAs($listing->developer);

        $this->get(route('v1.apps.download', $listing))->assertOk();
    }

    public function test_unpublished_apk_is_only_available_to_its_developer_and_reviewers(): void
    {
        $listing = $this->listingWithApk(['price' => 49000], ListingStatus::InReview);

        Sanctum::actingAs($this->player);
        $this->getJson(route('v1.apps.download', $listing))->assertNotFound();

        Sanctum::actingAs($listing->developer);
        $this->get(route('v1.apps.download', $listing))->assertOk();

        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Moderator->value));
        $this->get(route('v1.apps.download', $listing))->assertOk();
    }

    public function test_listing_without_an_apk_cannot_be_downloaded(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        Sanctum::actingAs($listing->developer);

        $this->getJson(route('v1.apps.download', $listing))->assertNotFound();
    }

    public function test_guests_cannot_check_their_access(): void
    {
        $listing = $this->listingWithApk();

        $this->getJson(route('v1.apps.access', $listing))->assertUnauthorized();
    }

    public function test_owner_can_download_their_paid_apk(): void
    {
        $listing = $this->listingWithApk(['price' => 49000]);
        Sanctum::actingAs($listing->developer);

        $this->getJson(route('v1.apps.access', $listing))
            ->assertOk()
            ->assertExactJson(['data' => ['can_download' => true, 'is_owner' => true]]);
    }

    public function test_player_must_buy_a_paid_apk_until_they_own_it(): void
    {
        $listing = $this->listingWithApk(['price' => 49000]);
        Sanctum::actingAs($this->player);

        $this->getJson(route('v1.apps.access', $listing))
            ->assertOk()
            ->assertExactJson(['data' => ['can_download' => false, 'is_owner' => false]]);

        Purchase::factory()->for($this->player)->for($listing)->create();

        $this->getJson(route('v1.apps.access', $listing))
            ->assertOk()
            ->assertJsonPath('data.can_download', true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function listingWithApk(array $attributes = [], ListingStatus $status = ListingStatus::Published): Listing
    {
        $listing = Listing::factory()->status($status)->create($attributes);
        $path = "listings/{$listing->id}/game.apk";
        Storage::disk('apks')->put($path, 'apk-bytes');
        $listing->attachApk($path, 'game.apk', 9, hash('sha256', 'apk-bytes'));

        return $listing;
    }
}
