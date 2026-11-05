<?php

namespace Tests\Feature\Api\V1\Developer;

use App\Enums\ListingInviteStatus;
use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Models\Admin;
use App\Models\Listing;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ListingInvitesAwaitingReview;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivateAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->developer = User::factory()->create();
        $this->developer->assignRole(Role::Developer->value);
        Storage::fake('apks');
    }

    /**
     * @param  list<string>  $emails
     */
    private function privateListing(array $emails = []): Listing
    {
        $listing = Listing::factory()->for($this->developer, 'developer')->status(ListingStatus::Published)->create();
        Storage::disk('apks')->put('private.apk', 'apk');
        $listing->forceFill(['is_private' => true, 'apk_path' => 'private.apk'])->save();
        $listing->invites()->createMany(array_map(fn (string $email): array => ['email' => $email], $emails));

        return $listing;
    }

    public function test_a_private_listing_is_hidden_from_guests_and_uninvited_members_everywhere(): void
    {
        $listing = $this->privateListing(['friend@example.com']);

        $this->getJson(route('v1.apps.index'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('v1.apps.show', $listing))->assertNotFound();
        $this->getJson(route('v1.apps.comments.index', $listing))->assertNotFound();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson(route('v1.apps.show', $listing))->assertNotFound();
        $this->getJson(route('v1.apps.access', $listing))->assertNotFound();
        $this->getJson(route('v1.apps.comments.index', $listing))->assertNotFound();
        $this->get(route('v1.apps.download', $listing))->assertNotFound();
        $this->postJson(route('v1.apps.comments.store', $listing), ['body' => 'این اپ عالی است'])->assertNotFound();
        $this->getJson(route('v1.me.private-apps'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_invited_member_can_see_and_download_it_whatever_case_their_email_is_in(): void
    {
        $listing = $this->privateListing(['friend@example.com']);
        Sanctum::actingAs(User::factory()->create(['email' => 'Friend@Example.com']));

        $this->getJson(route('v1.apps.show', $listing))->assertOk()->assertJsonPath('data.is_private', true);
        $this->getJson(route('v1.apps.access', $listing))->assertOk()->assertJsonPath('data.can_download', true);
        $this->get(route('v1.apps.download', $listing))->assertOk();
        $this->getJson(route('v1.me.private-apps'))->assertOk()->assertJsonPath('data.0.slug', $listing->slug);

        // the shared catalog still leaves it out, because that response is cached for everyone
        $this->getJson(route('v1.apps.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_invited_email_that_is_not_verified_gets_nothing(): void
    {
        $listing = $this->privateListing(['friend@example.com']);
        Sanctum::actingAs(User::factory()->unverified()->create(['email' => 'friend@example.com']));

        $this->getJson(route('v1.apps.show', $listing))->assertNotFound();
        $this->get(route('v1.apps.download', $listing))->assertNotFound();
        $this->getJson(route('v1.me.private-apps'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_developer_can_open_their_own_private_listing(): void
    {
        $listing = $this->privateListing();
        Sanctum::actingAs($this->developer);

        $this->getJson(route('v1.apps.show', $listing))->assertOk();
        $this->get(route('v1.apps.download', $listing))->assertOk();
    }

    public function test_a_staff_token_does_not_count_as_an_invited_member(): void
    {
        $listing = $this->privateListing(['staff@example.com']);
        Sanctum::actingAs(Admin::factory()->create(['email' => 'staff@example.com']), guard: 'sanctum');

        $this->getJson(route('v1.apps.show', $listing))->assertNotFound();
    }

    public function test_removing_an_email_ends_access_straight_away(): void
    {
        $listing = $this->privateListing(['friend@example.com']);
        $friend = User::factory()->create(['email' => 'friend@example.com']);

        Sanctum::actingAs($this->developer);
        $this->putJson(route('v1.developer.apps.private-access', $listing), ['is_private' => true, 'emails' => []])->assertOk();

        Sanctum::actingAs($friend);
        $this->get(route('v1.apps.download', $listing))->assertNotFound();
    }

    public function test_the_developer_saves_the_list_with_duplicates_merged(): void
    {
        $listing = Listing::factory()->for($this->developer, 'developer')->create();
        Sanctum::actingAs($this->developer);

        $this->putJson(route('v1.developer.apps.private-access', $listing), [
            'is_private' => true,
            'emails' => [' B@example.com ', 'b@example.com', 'a@example.com'],
        ])->assertOk()
            ->assertJsonPath('data.is_private', true)
            ->assertJsonPath('data.private_access.emails', ['a@example.com', 'b@example.com']);

        $this->getJson(route('v1.developer.apps.show', $listing))
            ->assertJsonPath('data.private_access.max_emails', 100);
    }

    public function test_an_email_with_an_account_is_active_and_one_without_waits_for_staff(): void
    {
        Notification::fake();
        Admin::factory()->create()->assignRole('admin');
        $listing = Listing::factory()->for($this->developer, 'developer')->status(ListingStatus::Published)->create();
        User::factory()->create(['email' => 'known@example.com']);
        Sanctum::actingAs($this->developer);

        $this->putJson(route('v1.developer.apps.private-access', $listing), [
            'is_private' => true,
            'emails' => ['known@example.com', 'ghost@example.com'],
        ])->assertOk()
            ->assertJsonPath('pending_review', ['ghost@example.com'])
            ->assertJsonPath('data.private_access.invites.0.email', 'ghost@example.com')
            ->assertJsonPath('data.private_access.invites.0.status', 'pending')
            ->assertJsonPath('data.private_access.invites.1.status', 'active');

        Notification::assertSentTimes(ListingInvitesAwaitingReview::class, 1);
    }

    public function test_a_pending_email_opens_nothing_until_staff_approve_it(): void
    {
        $listing = $this->privateListing();
        $invite = $listing->invites()->create(['email' => 'late@example.com', 'status' => ListingInviteStatus::Pending]);
        $late = User::factory()->create(['email' => 'late@example.com']);

        Sanctum::actingAs($late);
        $this->getJson(route('v1.apps.show', $listing))->assertNotFound();
        $this->get(route('v1.apps.download', $listing))->assertNotFound();
        $this->getJson(route('v1.me.private-apps'))->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs(Admin::factory()->create()->assignRole('admin'));
        $this->getJson(route('v1.admin.listings.show', $listing))->assertOk()
            ->assertJsonPath('data.private_access.invites.0.status', 'pending');
        $this->postJson(route('v1.admin.listings.invites.approve', [$listing, $invite]))->assertOk();
        $this->postJson(route('v1.admin.listings.invites.reject', [$listing, $invite]))->assertConflict();

        Sanctum::actingAs($late);
        $this->getJson(route('v1.apps.show', $listing))->assertOk();
    }

    public function test_staff_can_reject_a_pending_email_and_saving_again_keeps_the_decision(): void
    {
        Notification::fake();
        $listing = $this->privateListing();
        $invite = $listing->invites()->create(['email' => 'ghost@example.com', 'status' => ListingInviteStatus::Pending]);

        Sanctum::actingAs(Admin::factory()->create()->assignRole('admin'));
        $this->postJson(route('v1.admin.listings.invites.reject', [$listing, $invite]))->assertOk();

        Sanctum::actingAs($this->developer);
        $this->putJson(route('v1.developer.apps.private-access', $listing), ['is_private' => true, 'emails' => ['ghost@example.com']])
            ->assertOk()
            ->assertJsonPath('pending_review', [])
            ->assertJsonPath('data.private_access.invites.0.status', 'rejected');

        Notification::assertNothingSent();
    }

    public function test_an_invite_of_another_listing_cannot_be_decided_through_this_one(): void
    {
        $listing = $this->privateListing();
        $other = Listing::factory()->for($this->developer, 'developer')->create();
        $invite = $other->invites()->create(['email' => 'ghost@example.com', 'status' => ListingInviteStatus::Pending]);

        Sanctum::actingAs(Admin::factory()->create()->assignRole('admin'));
        $this->postJson(route('v1.admin.listings.invites.approve', [$listing, $invite]))->assertNotFound();
    }

    public function test_only_reviewers_decide_on_pending_emails(): void
    {
        $listing = $this->privateListing();
        $invite = $listing->invites()->create(['email' => 'ghost@example.com', 'status' => ListingInviteStatus::Pending]);

        Sanctum::actingAs(Admin::factory()->create()->assignRole('curator'));
        $this->postJson(route('v1.admin.listings.invites.approve', [$listing, $invite]))->assertForbidden();
        $this->assertSame(ListingInviteStatus::Pending, $invite->fresh()->status);
    }

    public function test_an_email_that_has_since_signed_up_becomes_active_when_the_list_is_saved(): void
    {
        $listing = $this->privateListing();
        $listing->invites()->create(['email' => 'late@example.com', 'status' => ListingInviteStatus::Pending]);
        User::factory()->create(['email' => 'late@example.com']);
        Sanctum::actingAs($this->developer);

        $this->putJson(route('v1.developer.apps.private-access', $listing), ['is_private' => true, 'emails' => ['late@example.com']])
            ->assertOk()
            ->assertJsonPath('data.private_access.invites.0.status', 'active');
    }

    public function test_the_limit_comes_from_the_admin_setting(): void
    {
        Setting::put(['private_listing_max_emails' => 2]);
        $listing = Listing::factory()->for($this->developer, 'developer')->create();
        Sanctum::actingAs($this->developer);

        $this->putJson(route('v1.developer.apps.private-access', $listing), [
            'is_private' => true,
            'emails' => ['a@example.com', 'b@example.com', 'c@example.com'],
        ])->assertUnprocessable()->assertJsonValidationErrors('emails');
        $this->assertSame(0, $listing->invites()->count());

        $this->putJson(route('v1.developer.apps.private-access', $listing), [
            'is_private' => true,
            'emails' => ['a@example.com', 'b@example.com'],
        ])->assertOk();
    }

    public function test_only_the_owner_can_change_the_list(): void
    {
        $listing = $this->privateListing();
        Sanctum::actingAs(tap(User::factory()->create())->assignRole(Role::Developer->value));

        $this->putJson(route('v1.developer.apps.private-access', $listing), ['is_private' => false, 'emails' => []])->assertForbidden();
        $this->assertTrue($listing->fresh()->is_private);
    }

    public function test_opening_an_approved_private_listing_to_everyone_needs_review(): void
    {
        $listing = $this->privateListing(['friend@example.com']);
        Sanctum::actingAs($this->developer);

        $this->putJson(route('v1.developer.apps.private-access', $listing), ['is_private' => false, 'emails' => []])
            ->assertOk()
            ->assertJsonPath('data.status', ListingStatus::InReview->value);
    }

    public function test_making_a_published_listing_private_applies_without_review(): void
    {
        $listing = Listing::factory()->for($this->developer, 'developer')->status(ListingStatus::Published)->create();
        Sanctum::actingAs($this->developer);

        $this->putJson(route('v1.developer.apps.private-access', $listing), ['is_private' => true, 'emails' => []])
            ->assertOk()
            ->assertJsonPath('data.status', ListingStatus::Published->value);
    }

    public function test_the_type_of_release_cannot_change_during_review(): void
    {
        $listing = Listing::factory()->for($this->developer, 'developer')->status(ListingStatus::InReview)->create();
        Sanctum::actingAs($this->developer);

        $this->putJson(route('v1.developer.apps.private-access', $listing), ['is_private' => true, 'emails' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('is_private');

        // the list itself can still change, while it waits
        $this->putJson(route('v1.developer.apps.private-access', $listing), ['is_private' => false, 'emails' => ['a@example.com']])->assertOk();
    }

    public function test_a_copy_keeps_the_listing_private_without_its_invites(): void
    {
        $copy = $this->privateListing(['friend@example.com'])->duplicate('com.example.copy');

        $this->assertTrue($copy->is_private);
        $this->assertSame(0, $copy->invites()->count());
    }

    public function test_admins_set_the_limit_in_settings(): void
    {
        $admin = Admin::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin);

        $this->putJson('/api/v1/admin/settings', ['private_listing_max_emails' => 250])
            ->assertOk()
            ->assertJsonPath('data.private_listing_max_emails', 250);
        $this->putJson('/api/v1/admin/settings', ['private_listing_max_emails' => 0])->assertUnprocessable();
    }
}
