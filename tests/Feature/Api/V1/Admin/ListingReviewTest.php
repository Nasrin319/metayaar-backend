<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListingReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function reviewerRoles(): array
    {
        return [
            'admin' => [Role::Admin],
            'moderator' => [Role::Moderator],
        ];
    }

    #[DataProvider('reviewerRoles')]
    public function test_reviewers_see_the_queue_oldest_submission_first(Role $role): void
    {
        $this->actingAsRole($role);
        $newer = Listing::factory()->status(ListingStatus::InReview)->create(['submitted_at' => now()]);
        $older = Listing::factory()->status(ListingStatus::InReview)->create(['submitted_at' => now()->minus(days: 2)]);
        Listing::factory()->status(ListingStatus::Draft)->create();

        $this->getJson(route('v1.admin.listings.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.1.id', $newer->id)
            ->assertJsonPath('data.0.developer.email', $older->developer->email);

        $this->getJson(route('v1.admin.listings.index', ['status' => 'draft']))->assertJsonCount(1, 'data');
    }

    #[DataProvider('reviewerRoles')]
    public function test_reviewers_approve_a_listing_in_review(Role $role): void
    {
        $reviewer = $this->actingAsRole($role);
        $listing = Listing::factory()->status(ListingStatus::InReview)->create();

        $this->postJson(route('v1.admin.listings.approve', $listing))
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertSame($reviewer->id, $listing->refresh()->reviewed_by);
        $this->assertNotNull($listing->reviewed_at);
    }

    public function test_rejection_needs_a_reason_that_the_developer_sees(): void
    {
        $this->actingAsRole(Role::Moderator);
        $listing = Listing::factory()->status(ListingStatus::InReview)->create();

        $this->postJson(route('v1.admin.listings.reject', $listing))->assertJsonValidationErrors('reason');
        $this->postJson(route('v1.admin.listings.reject', $listing), ['reason' => 'The APK crashes on start.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        Sanctum::actingAs($listing->developer->assignRole(Role::Developer->value));
        $this->getJson(route('v1.developer.apps.show', $listing))
            ->assertJsonPath('data.rejection_reason', 'The APK crashes on start.');
    }

    public function test_only_listings_in_review_can_be_reviewed(): void
    {
        $this->actingAsRole(Role::Admin);
        $draft = Listing::factory()->status(ListingStatus::Draft)->create();

        $this->postJson(route('v1.admin.listings.approve', $draft))->assertConflict();
        $this->assertSame(ListingStatus::Draft, $draft->refresh()->status);
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function nonReviewerRoles(): array
    {
        return [
            'player' => [Role::Player],
            'developer' => [Role::Developer],
            'curator' => [Role::Curator],
        ];
    }

    #[DataProvider('nonReviewerRoles')]
    public function test_other_roles_cannot_use_the_admin_panel(Role $role): void
    {
        $this->actingAsRole($role);
        $listing = Listing::factory()->status(ListingStatus::InReview)->create();

        $this->getJson(route('v1.admin.dashboard'))->assertForbidden();
        $this->getJson(route('v1.admin.listings.index'))->assertForbidden();
        $this->postJson(route('v1.admin.listings.approve', $listing))->assertForbidden();
        $this->assertSame(ListingStatus::InReview, $listing->refresh()->status);
    }

    public function test_dashboard_counts_listings_by_status(): void
    {
        $this->actingAsRole(Role::Moderator);
        Listing::factory()->count(2)->status(ListingStatus::InReview)->create();
        Listing::factory()->status(ListingStatus::Published)->create();

        $this->getJson(route('v1.admin.dashboard'))
            ->assertOk()
            ->assertJsonPath('data.listings.in_review', 2)
            ->assertJsonPath('data.listings.published', 1)
            ->assertJsonPath('data.listings.rejected', 0);
    }

    private function actingAsRole(Role $role): User
    {
        $user = User::factory()->create()->assignRole($role->value);
        Sanctum::actingAs($user);

        return $user;
    }
}
