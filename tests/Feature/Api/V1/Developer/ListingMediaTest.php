<?php

namespace Tests\Feature\Api\V1\Developer;

use App\Enums\ListingStatus;
use App\Enums\Role;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingMediaTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(RoleSeeder::class);
        $this->developer = User::factory()->create()->assignRole(Role::Developer->value);
        $this->listing = Listing::factory()->for($this->developer, 'developer')->create();
        Sanctum::actingAs($this->developer);
    }

    public function test_developer_uploads_an_icon_and_replacing_it_deletes_the_old_file(): void
    {
        $this->postJson(route('v1.developer.apps.media.store', [$this->listing, 'icon']), [
            'image' => UploadedFile::fake()->image('icon.png', 512, 512),
        ])->assertOk();

        $firstPath = $this->listing->refresh()->icon_path;
        Storage::disk('public')->assertExists($firstPath);

        $response = $this->postJson(route('v1.developer.apps.media.store', [$this->listing, 'icon']), [
            'image' => UploadedFile::fake()->image('icon-2.png', 512, 512),
        ])->assertOk();

        $secondPath = $this->listing->refresh()->icon_path;
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
        $response->assertJsonPath('data.icon_url', Storage::disk('public')->url($secondPath));
    }

    public function test_developer_uploads_and_removes_a_title_image(): void
    {
        $this->postJson(route('v1.developer.apps.media.store', [$this->listing, 'title_image']), [
            'image' => UploadedFile::fake()->image('title.png', 1024, 256),
        ])->assertOk();

        $path = $this->listing->refresh()->title_image_path;
        Storage::disk('public')->assertExists($path);

        $this->deleteJson(route('v1.developer.apps.media.destroy', [$this->listing, 'title_image']))
            ->assertOk()
            ->assertJsonPath('data.title_image_url', null);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_developer_adds_and_removes_screenshots(): void
    {
        foreach (['one.jpg', 'two.jpg'] as $name) {
            $this->postJson(route('v1.developer.apps.media.store', [$this->listing, 'screenshot']), [
                'image' => UploadedFile::fake()->image($name, 1920, 1080),
            ])->assertOk();
        }

        [$first, $second] = $this->listing->refresh()->screenshot_paths;

        $this->deleteJson(route('v1.developer.apps.media.destroy', [$this->listing, 'screenshot', 0]))
            ->assertOk()
            ->assertJsonCount(1, 'data.screenshots');

        $this->assertSame([$second], $this->listing->refresh()->screenshot_paths);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_screenshots_are_limited(): void
    {
        $this->listing->forceFill(['screenshot_paths' => array_fill(0, Listing::MAX_SCREENSHOTS, 'listings/x.jpg')])->save();

        $this->postJson(route('v1.developer.apps.media.store', [$this->listing, 'screenshot']), [
            'image' => UploadedFile::fake()->image('extra.jpg', 1920, 1080),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['image' => 'A listing can have at most 8 screenshots.']);
    }

    public function test_images_must_be_large_enough(): void
    {
        $this->postJson(route('v1.developer.apps.media.store', [$this->listing, 'icon']), [
            'image' => UploadedFile::fake()->image('icon.png', 64, 64),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['image' => 'The image field has invalid image dimensions.']);
    }

    public function test_only_images_are_accepted(): void
    {
        $this->postJson(route('v1.developer.apps.media.store', [$this->listing, 'cover']), [
            'image' => UploadedFile::fake()->create('cover.pdf', 100, 'application/pdf'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['image' => 'The image field must be an image.']);
    }

    public function test_unknown_media_kinds_are_not_found(): void
    {
        $this->postJson(route('v1.developer.apps.media.store', [$this->listing, 'banner']), [
            'image' => UploadedFile::fake()->image('banner.png', 1920, 1080),
        ])->assertNotFound();
    }

    public function test_removing_a_missing_screenshot_is_not_found(): void
    {
        $this->deleteJson(route('v1.developer.apps.media.destroy', [$this->listing, 'screenshot', 3]))->assertNotFound();
    }

    public function test_developer_cannot_change_images_of_someone_elses_listing(): void
    {
        $other = Listing::factory()->create();

        $this->postJson(route('v1.developer.apps.media.store', [$other, 'cover']), [
            'image' => UploadedFile::fake()->image('cover.png', 1920, 1080),
        ])->assertForbidden();

        $this->assertNull($other->refresh()->cover_path);
    }

    public function test_images_are_locked_while_the_listing_is_in_review(): void
    {
        $this->listing->forceFill(['status' => ListingStatus::InReview])->save();

        $this->postJson(route('v1.developer.apps.media.store', [$this->listing, 'cover']), [
            'image' => UploadedFile::fake()->image('cover.png', 1920, 1080),
        ])->assertForbidden();
    }

    public function test_deleting_a_listing_deletes_its_images(): void
    {
        $this->postJson(route('v1.developer.apps.media.store', [$this->listing, 'cover']), [
            'image' => UploadedFile::fake()->image('cover.png', 1920, 1080),
        ])->assertOk();

        $this->deleteJson(route('v1.developer.apps.destroy', $this->listing))->assertNoContent();

        Storage::disk('public')->assertDirectoryEmpty("listings/{$this->listing->id}");
    }
}
