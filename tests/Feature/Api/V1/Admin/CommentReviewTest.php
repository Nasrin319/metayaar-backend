<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\ListingStatus;
use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\Comment;
use App\Models\Listing;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommentReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array<string, array{StaffRole}>
     */
    public static function moderatorRoles(): array
    {
        return [
            'admin' => [StaffRole::Admin],
            'moderator' => [StaffRole::Moderator],
        ];
    }

    #[DataProvider('moderatorRoles')]
    public function test_moderators_see_waiting_comments_and_replies_oldest_first(StaffRole $role): void
    {
        $this->actingAsStaff($role);
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        $comment = Comment::factory()->approved()->for($listing)->create();
        $older = Comment::factory()->for($listing)->create(['created_at' => now()->subHour()]);
        $reply = Comment::factory()->replyTo($comment)->create();

        $this->getJson(route('v1.admin.comments.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pending_total', 2)
            ->assertJsonPath('data.0.id', $older->id)
            ->assertJsonPath('data.0.user.email', $older->user->email)
            ->assertJsonPath('data.0.listing.title', $listing->title)
            ->assertJsonPath('data.1.id', $reply->id)
            ->assertJsonPath('data.1.parent.id', $comment->id);

        $this->getJson(route('v1.admin.comments.index', ['status' => 'approved']))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $comment->id);
    }

    public function test_approving_a_comment_shows_it_in_the_store(): void
    {
        $admin = $this->actingAsStaff(StaffRole::Moderator);
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        $comment = Comment::factory()->for($listing)->create();

        $this->postJson(route('v1.admin.comments.approve', $comment))
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'status' => 'approved', 'reviewed_by' => $admin->id]);

        $this->getJson(route('v1.apps.comments.index', $listing))->assertJsonPath('data.0.id', $comment->id);
    }

    public function test_rejecting_keeps_a_comment_out_of_the_store(): void
    {
        $this->actingAsStaff(StaffRole::Moderator);
        $comment = Comment::factory()->approved()->create();

        $this->postJson(route('v1.admin.comments.reject', $comment), ['reason' => 'توهین‌آمیز'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'توهین‌آمیز');

        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'status' => 'rejected']);
    }

    public function test_staff_delete_comments(): void
    {
        $this->actingAsStaff(StaffRole::Admin);
        $comment = Comment::factory()->create();

        $this->deleteJson(route('v1.admin.comments.destroy', $comment))->assertOk();

        $this->assertModelMissing($comment);
    }

    public function test_staff_without_the_permission_cannot_moderate(): void
    {
        $this->actingAsStaff(StaffRole::Curator);
        $comment = Comment::factory()->create();

        $this->getJson(route('v1.admin.comments.index'))->assertForbidden();
        $this->postJson(route('v1.admin.comments.approve', $comment))->assertForbidden();
    }

    public function test_website_users_cannot_moderate(): void
    {
        $comment = Comment::factory()->create();
        Sanctum::actingAs($comment->user);

        $this->postJson(route('v1.admin.comments.approve', $comment))->assertForbidden();
    }

    private function actingAsStaff(StaffRole $role): Admin
    {
        $admin = Admin::factory()->create()->assignRole($role->value);
        Sanctum::actingAs($admin);

        return $admin;
    }
}
