<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\Comment;
use App\Models\Listing;
use App\Models\User;
use App\Notifications\CommentAwaitingReview;
use App\Notifications\ListingAwaitingReview;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Admin $moderator;

    private Admin $curator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = Admin::factory()->create()->assignRole(StaffRole::Admin->value);
        $this->moderator = Admin::factory()->create()->assignRole(StaffRole::Moderator->value);
        $this->curator = Admin::factory()->create()->assignRole(StaffRole::Curator->value);
    }

    public function test_a_new_comment_notifies_the_staff_who_moderate_comments(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create(['title' => 'Beat Run']);
        $member = User::factory()->create(['name' => 'Sara']);
        Sanctum::actingAs($member);

        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => 'بازی خیلی خوبی است'])->assertCreated();

        $this->assertCount(1, $this->admin->notifications);
        $this->assertCount(1, $this->moderator->notifications);
        $this->assertCount(0, $this->curator->notifications);

        $notification = $this->moderator->notifications->first();
        $this->assertSame(CommentAwaitingReview::class, $notification->type);
        $this->assertSame('comment', $notification->data['kind']);
        $this->assertSame('نظر جدید برای «Beat Run»', $notification->data['title']);
        $this->assertSame('Sara: بازی خیلی خوبی است', $notification->data['body']);
        $this->assertSame('/comments', $notification->data['url']);
    }

    public function test_a_developer_reply_notifies_moderators_too(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        $comment = Comment::factory()->approved()->for($listing)->create();
        Sanctum::actingAs($listing->developer);

        $this->postJson(route('v1.comments.replies.store', $comment), ['body' => 'ممنون از شما'])->assertCreated();

        $this->assertSame('reply', $this->moderator->notifications->first()->data['kind']);
    }

    public function test_submitting_an_app_notifies_the_staff_who_review_apps(): void
    {
        $listing = $this->submittableListing(['title' => 'Sky Glide']);
        Sanctum::actingAs($listing->developer);

        $this->postJson(route('v1.developer.apps.submit', $listing))->assertOk();

        $this->assertCount(1, $this->admin->notifications);
        $this->assertCount(1, $this->moderator->notifications);
        $this->assertCount(0, $this->curator->notifications);

        $notification = $this->moderator->notifications->first();
        $this->assertSame(ListingAwaitingReview::class, $notification->type);
        $this->assertSame('اپ جدید برای بررسی: «Sky Glide»', $notification->data['title']);
        $this->assertSame("/listings/{$listing->id}", $notification->data['url']);
    }

    public function test_a_resubmitted_app_says_so(): void
    {
        $listing = $this->submittableListing(['title' => 'Sky Glide', 'status' => ListingStatus::Rejected]);
        Sanctum::actingAs($listing->developer);

        $this->postJson(route('v1.developer.apps.submit', $listing))->assertOk();

        $this->assertSame('«Sky Glide» دوباره برای بررسی ارسال شد', $this->moderator->notifications->first()->data['title']);
    }

    public function test_moderating_a_comment_marks_its_notification_read_for_everyone(): void
    {
        $comment = $this->postCommentAsMember();
        Sanctum::actingAs($this->moderator);

        $this->postJson(route('v1.admin.comments.approve', $comment))->assertOk();

        $this->assertCount(0, $this->moderator->unreadNotifications()->get());
        $this->assertCount(0, $this->admin->unreadNotifications()->get());
    }

    public function test_reviewing_an_app_marks_its_notification_read(): void
    {
        $listing = $this->submittableListing();
        Sanctum::actingAs($listing->developer);
        $this->postJson(route('v1.developer.apps.submit', $listing))->assertOk();

        Sanctum::actingAs($this->admin);
        $this->postJson(route('v1.admin.listings.reject', $listing), ['reason' => 'تصاویر کافی نیست'])->assertOk();

        $this->assertCount(0, $this->moderator->unreadNotifications()->get());
    }

    public function test_deleting_a_comment_removes_its_notifications(): void
    {
        $comment = $this->postCommentAsMember();
        Sanctum::actingAs($comment->user);

        $this->deleteJson(route('v1.comments.destroy', $comment))->assertOk();

        $this->assertCount(0, $this->moderator->notifications()->get());
    }

    public function test_staff_list_their_notifications_and_mark_them_read(): void
    {
        $this->postCommentAsMember();
        $this->postCommentAsMember();
        Sanctum::actingAs($this->moderator);

        $response = $this->getJson(route('v1.admin.notifications.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('data.0.kind', 'comment')
            ->assertJsonPath('data.0.read_at', null);

        $this->getJson(route('v1.admin.notifications.unread-count'))->assertExactJson(['unread_count' => 2]);

        $this->postJson(route('v1.admin.notifications.read', $response->json('data.1.id')))
            ->assertOk()
            ->assertJsonPath('unread_count', 1);

        $this->getJson(route('v1.admin.notifications.index', ['unread' => 1]))->assertJsonCount(1, 'data');

        $this->postJson(route('v1.admin.notifications.read-all'))->assertExactJson(['unread_count' => 0]);
        $this->assertSame(0, $this->moderator->unreadNotifications()->count());
        // marking read is per person: the admin still has both
        $this->assertSame(2, $this->admin->unreadNotifications()->count());
    }

    public function test_staff_cannot_read_someone_elses_notifications(): void
    {
        $this->postCommentAsMember();
        $notification = $this->moderator->notifications()->first();
        Sanctum::actingAs($this->curator);

        $this->postJson(route('v1.admin.notifications.read', $notification->id))->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_website_users_have_no_staff_notifications(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(route('v1.admin.notifications.index'))->assertForbidden();
    }

    /**
     * A developer's app ready to submit: an external link, so no APK or package name is needed.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function submittableListing(array $attributes = []): Listing
    {
        $developer = User::factory()->create()->assignRole(Role::Developer->value);

        return Listing::factory()->external()->for($developer, 'developer')->create(['price' => 0, ...$attributes]);
    }

    private function postCommentAsMember(): Comment
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => 'نظر آزمایشی'])->assertCreated();

        return Comment::latest('id')->first();
    }
}
