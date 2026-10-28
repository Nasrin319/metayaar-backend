<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_only_published_listings_newest_first(): void
    {
        Listing::factory()->status(ListingStatus::Published)->create(['title' => 'Older', 'reviewed_at' => now()->subDay()]);
        Listing::factory()->status(ListingStatus::Published)->create(['title' => 'Newer', 'reviewed_at' => now()]);
        Listing::factory()->create(['title' => 'Draft']);
        Listing::factory()->status(ListingStatus::InReview)->create(['title' => 'In review']);
        Listing::factory()->status(ListingStatus::Rejected)->create(['title' => 'Rejected']);

        $this->getJson(route('v1.apps.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Newer')
            ->assertJsonPath('data.1.title', 'Older');
    }

    public function test_catalog_hides_review_and_file_details(): void
    {
        $developer = User::factory()->create(['name' => 'Studio', 'email' => 'studio@example.com']);
        Listing::factory()->for($developer, 'developer')->status(ListingStatus::Published)->create([
            'rejection_reason' => 'old note',
        ]);

        $listing = $this->getJson(route('v1.apps.index'))->assertOk()->json('data.0');

        $this->assertSame(['name' => 'Studio'], $listing['developer']);
        $this->assertArrayNotHasKey('id', $listing);
        $this->assertArrayNotHasKey('status', $listing);
        $this->assertArrayNotHasKey('rejection_reason', $listing);
        $this->assertArrayNotHasKey('package_name', $listing);
        $this->assertArrayNotHasKey('apk', $listing);
    }

    public function test_catalog_filters_by_search_category_and_price(): void
    {
        $published = Listing::factory()->status(ListingStatus::Published);
        $published->create(['title' => 'Neon Blade', 'category' => 'action', 'price' => 0]);
        $published->create(['title' => 'Sky Garden', 'category' => 'adventure', 'price' => 0]);
        $published->create(['title' => 'Neon Racer', 'category' => 'action', 'price' => 49000]);

        $this->getJson(route('v1.apps.index', ['q' => 'neon']))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson(route('v1.apps.index', ['category' => 'adventure']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Sky Garden');

        $this->getJson(route('v1.apps.index', ['category' => 'action', 'free' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Neon Blade');
    }

    public function test_catalog_sorts_by_price(): void
    {
        $published = Listing::factory()->status(ListingStatus::Published);
        $published->create(['title' => 'Mid', 'price' => 20000]);
        $published->create(['title' => 'Cheap', 'price' => 0]);
        $published->create(['title' => 'Pricey', 'price' => 90000]);

        $this->getJson(route('v1.apps.index', ['sort' => 'price_asc']))
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Cheap', 'Mid', 'Pricey']);

        $this->getJson(route('v1.apps.index', ['sort' => 'price_desc']))
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Pricey', 'Mid', 'Cheap']);
    }

    public function test_catalog_rejects_an_unknown_sort(): void
    {
        $this->getJson(route('v1.apps.index', ['sort' => 'random']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort' => 'The selected sort is invalid.']);
    }

    public function test_guests_can_open_a_published_listing_by_slug(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create(['title' => 'Neon Blade']);

        $this->getJson(route('v1.apps.show', $listing))
            ->assertOk()
            ->assertJsonPath('data.slug', 'neon-blade')
            ->assertJsonPath('data.title', 'Neon Blade');
    }

    public function test_unpublished_listings_are_not_found(): void
    {
        $draft = Listing::factory()->create();

        $this->getJson(route('v1.apps.show', $draft))->assertNotFound();
    }
}
