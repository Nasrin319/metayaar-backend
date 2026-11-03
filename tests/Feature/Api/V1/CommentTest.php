<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CommentStatus;
use App\Enums\ListingStatus;
use App\Models\Comment;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_player_comments_on_a_published_app_and_it_waits_for_approval(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        $player = User::factory()->create();
        Sanctum::actingAs($player);

        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => '  بازی خیلی خوبی است.  '])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.body', 'بازی خیلی خوبی است.')
            ->assertJsonPath('message', 'نظر شما ثبت شد و پس از تأیید مدیر نمایش داده می‌شود.');

        $this->assertDatabaseHas('comments', [
            'listing_id' => $listing->id,
            'user_id' => $player->id,
            'parent_id' => null,
            'status' => 'pending',
        ]);
    }

    public function test_comments_need_a_signed_in_user_and_text(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();

        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => 'سلام'])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body' => 'وارد کردن متن نظر الزامی است.']);
    }

    public function test_comments_with_html_or_script_are_refused(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => 'عالی <script>alert(1)</script>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['body' => 'متن نظر نباید شامل کد یا تگ HTML (مثل <script>) باشد.']);

        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => "Great on Quest 2; I'd select it again"])
            ->assertCreated();

        $this->assertDatabaseCount('comments', 1);
    }

    public function test_unpublished_apps_take_no_comments(): void
    {
        $listing = Listing::factory()->status(ListingStatus::InReview)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => 'نظر من'])->assertNotFound();
        $this->getJson(route('v1.apps.comments.index', $listing))->assertNotFound();
    }

    public function test_developers_reply_instead_of_commenting_on_their_own_app(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        Sanctum::actingAs($listing->developer);

        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => 'اپ من عالی است'])->assertForbidden();
    }

    public function test_the_store_shows_only_approved_comments_and_replies(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        $approved = Comment::factory()->approved()->for($listing)->create(['created_at' => now()->subDay()]);
        $newest = Comment::factory()->approved()->for($listing)->create();
        Comment::factory()->for($listing)->create();
        Comment::factory()->rejected()->for($listing)->create();
        $reply = Comment::factory()->approved()->replyTo($approved)->create();
        Comment::factory()->replyTo($newest)->create();

        $this->getJson(route('v1.apps.comments.index', $listing))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.approved_total', 2)
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonCount(0, 'data.0.replies')
            ->assertJsonPath('data.1.id', $approved->id)
            ->assertJsonPath('data.1.replies.0.id', $reply->id)
            ->assertJsonPath('data.1.replies.0.is_reply', true)
            ->assertJsonPath('data.1.user.name', $approved->user->name)
            ->assertJsonMissingPath('data.1.user.email');
    }

    public function test_signed_in_viewers_also_see_their_own_waiting_comments(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        $player = User::factory()->create();
        $mine = Comment::factory()->for($listing)->for($player)->create();
        Comment::factory()->for($listing)->create();

        Sanctum::actingAs($player);

        $this->getJson(route('v1.apps.comments.index', $listing))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.status', 'pending')
            ->assertJsonPath('meta.approved_total', 0);
    }

    public function test_the_developer_sees_their_own_waiting_reply(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        $comment = Comment::factory()->approved()->for($listing)->create();
        $reply = Comment::factory()->replyTo($comment)->create();

        Sanctum::actingAs($listing->developer);

        $this->getJson(route('v1.apps.comments.index', $listing))
            ->assertJsonPath('data.0.replies.0.id', $reply->id)
            ->assertJsonPath('data.0.replies.0.status', 'pending');
    }

    public function test_the_developer_replies_once_to_an_approved_comment(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        $comment = Comment::factory()->approved()->for($listing)->create();
        Sanctum::actingAs($listing->developer);

        $this->postJson(route('v1.comments.replies.store', $comment), ['body' => 'ممنون از نظرتان!'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_reply', true);

        $this->assertDatabaseHas('comments', [
            'parent_id' => $comment->id,
            'listing_id' => $listing->id,
            'user_id' => $listing->user_id,
            'status' => 'pending',
        ]);

        $this->postJson(route('v1.comments.replies.store', $comment), ['body' => 'پاسخ دوم'])
            ->assertConflict()
            ->assertJsonPath('message', 'برای این نظر قبلاً پاسخ ثبت کرده‌اید.');
    }

    public function test_the_developer_can_reply_again_after_a_reply_is_rejected(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        $comment = Comment::factory()->approved()->for($listing)->create();
        Comment::factory()->rejected()->replyTo($comment)->create();
        Sanctum::actingAs($listing->developer);

        $this->postJson(route('v1.comments.replies.store', $comment), ['body' => 'پاسخ اصلاح‌شده'])->assertCreated();
    }

    public function test_only_the_apps_developer_replies_and_only_to_approved_comments(): void
    {
        $listing = Listing::factory()->status(ListingStatus::Published)->create();
        $approved = Comment::factory()->approved()->for($listing)->create();
        $pending = Comment::factory()->for($listing)->create();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson(route('v1.comments.replies.store', $approved), ['body' => 'پاسخ'])->assertForbidden();

        Sanctum::actingAs($listing->developer);
        $this->postJson(route('v1.comments.replies.store', $pending), ['body' => 'پاسخ'])->assertConflict();

        $reply = Comment::factory()->approved()->replyTo($approved)->create();
        $this->postJson(route('v1.comments.replies.store', $reply), ['body' => 'پاسخ به پاسخ'])->assertConflict();
    }

    public function test_authors_delete_only_their_own_comments(): void
    {
        $comment = Comment::factory()->approved()->create();
        $reply = Comment::factory()->approved()->replyTo($comment)->create();

        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson(route('v1.comments.destroy', $comment))->assertForbidden();

        Sanctum::actingAs($comment->user);
        $this->deleteJson(route('v1.comments.destroy', $comment))->assertOk();

        $this->assertModelMissing($comment);
        $this->assertModelMissing($reply);
    }

    public function test_comment_status_is_pending_by_default(): void
    {
        $comment = Comment::factory()->create();

        $this->assertSame(CommentStatus::Pending, $comment->status);
    }
}
