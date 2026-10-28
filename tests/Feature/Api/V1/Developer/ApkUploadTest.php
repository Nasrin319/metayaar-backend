<?php

namespace Tests\Feature\Api\V1\Developer;

use App\Enums\ListingMedia;
use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Enums\Role;
use App\Models\ApkUpload;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class ApkUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('apks');
        Storage::fake('public');
        $this->seed(RoleSeeder::class);
        $this->developer = User::factory()->create()->assignRole(Role::Developer->value);
        $this->listing = Listing::factory()->for($this->developer, 'developer')->create();
        Sanctum::actingAs($this->developer);
    }

    public function test_developer_uploads_an_apk_in_chunks_and_it_is_attached_privately(): void
    {
        $apk = "PK\x03\x04".random_bytes(3000);

        $upload = $this->startUpload('neon-blade.apk', strlen($apk))
            ->assertCreated()
            ->assertJsonPath('data.received_bytes', 0)
            ->assertJsonPath('data.chunk_size', ApkUpload::CHUNK_SIZE)
            ->json('data.id');

        $this->sendChunk($upload, 0, substr($apk, 0, 1000))->assertOk()->assertJsonPath('data.received_bytes', 1000);
        $this->sendChunk($upload, 1000, substr($apk, 1000))->assertOk()->assertJsonPath('data.received_bytes', strlen($apk));

        $this->postJson(route('v1.developer.apps.apk-uploads.complete', [$this->listing, $upload]))
            ->assertOk()
            ->assertJsonPath('data.apk.original_name', 'neon-blade.apk')
            ->assertJsonPath('data.apk.size', strlen($apk))
            ->assertJsonPath('data.apk.sha256', hash('sha256', $apk))
            ->assertJsonMissingPath('data.apk_path');

        $path = $this->listing->refresh()->apk_path;
        $this->assertStringStartsWith("listings/{$this->listing->id}/", $path);
        $this->assertSame($apk, Storage::disk('apks')->get($path));
        $this->assertDatabaseMissing('apk_uploads', ['id' => $upload]);
        Storage::disk('apks')->assertMissing("uploads/{$upload}.part");
    }

    public function test_chunks_must_arrive_in_order_and_the_upload_can_resume(): void
    {
        $upload = $this->startUpload('game.apk', 2000)->json('data.id');
        $this->sendChunk($upload, 0, "PK\x03\x04".str_repeat('a', 996))->assertOk();

        $this->sendChunk($upload, 0, str_repeat('b', 1000))
            ->assertConflict()
            ->assertJsonPath('data.received_bytes', 1000);

        $this->getJson(route('v1.developer.apps.apk-uploads.show', [$this->listing, $upload]))
            ->assertOk()
            ->assertJsonPath('data.received_bytes', 1000);

        $this->sendChunk($upload, 1000, str_repeat('b', 1001))->assertJsonValidationErrors('chunk');
        $this->postJson(route('v1.developer.apps.apk-uploads.complete', [$this->listing, $upload]))
            ->assertJsonValidationErrors('chunk');
    }

    public function test_a_file_that_is_not_an_apk_is_rejected_and_discarded(): void
    {
        $upload = $this->startUpload('fake.apk', 10)->json('data.id');
        $this->sendChunk($upload, 0, 'not an apk')->assertOk();

        $this->postJson(route('v1.developer.apps.apk-uploads.complete', [$this->listing, $upload]))
            ->assertJsonValidationErrors('chunk');

        $this->assertNull($this->listing->refresh()->apk_path);
        $this->assertDatabaseMissing('apk_uploads', ['id' => $upload]);
        Storage::disk('apks')->assertMissing("uploads/{$upload}.part");
    }

    public function test_start_validates_the_file_and_the_listing(): void
    {
        $this->startUpload('game.zip', 100)->assertJsonValidationErrors('filename');
        $this->startUpload('game.apk', ApkUpload::MAX_SIZE + 1)->assertJsonValidationErrors('size');

        $inReview = Listing::factory()->for($this->developer, 'developer')->status(ListingStatus::InReview)->create();
        $this->postJson(route('v1.developer.apps.apk-uploads.store', $inReview), ['filename' => 'game.apk', 'size' => 100])
            ->assertForbidden();
    }

    public function test_developer_cannot_upload_to_another_developers_listing(): void
    {
        $other = Listing::factory()->create(['type' => ListingType::Apk]);
        $otherUpload = ApkUpload::factory()->for($other)->create();

        $this->postJson(route('v1.developer.apps.apk-uploads.store', $other), ['filename' => 'game.apk', 'size' => 100])
            ->assertForbidden();
        $this->getJson(route('v1.developer.apps.apk-uploads.show', [$this->listing, $otherUpload]))
            ->assertNotFound();
    }

    public function test_apk_listing_needs_a_file_before_it_can_be_submitted(): void
    {
        $this->postJson(route('v1.developer.apps.submit', $this->listing))->assertJsonValidationErrors('apk');

        $this->attachApk($this->listing);

        $this->postJson(route('v1.developer.apps.submit', $this->listing))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review');
    }

    public function test_replacing_or_deleting_removes_the_stored_file(): void
    {
        $this->attachApk($this->listing);
        $firstPath = $this->listing->apk_path;

        $this->attachApk($this->listing);
        Storage::disk('apks')->assertMissing($firstPath);
        Storage::disk('apks')->assertExists($this->listing->apk_path);

        $this->deleteJson(route('v1.developer.apps.destroy', $this->listing))->assertNoContent();
        Storage::disk('apks')->assertMissing($this->listing->apk_path);
    }

    public function test_replacing_the_apk_of_a_published_listing_sends_it_back_to_review(): void
    {
        $this->attachApk($this->listing);
        $this->listing->forceFill(['status' => ListingStatus::Published])->save();

        $this->attachApk($this->listing);

        $this->assertSame(ListingStatus::InReview, $this->listing->status);
    }

    public function test_uploading_an_apk_to_a_linked_listing_switches_it_to_apk(): void
    {
        $this->listing->forceFill(['type' => ListingType::WebXr, 'external_url' => 'https://example.com/xr'])->save();

        $this->uploadApk($this->apkWithIcons([]))
            ->assertOk()
            ->assertJsonPath('data.type', 'apk')
            ->assertJsonPath('data.external_url', null);
    }

    public function test_the_launcher_icon_in_the_apk_becomes_the_listing_icon(): void
    {
        $this->uploadApk($this->apkWithIcons(['mipmap-hdpi-v4' => 72, 'mipmap-xxxhdpi-v4' => 192]))
            ->assertOk()
            ->assertJsonPath('data.icon_from_apk', true);

        $iconPath = $this->listing->refresh()->icon_path;
        Storage::disk('public')->assertExists($iconPath);
        $this->assertSame(192, getimagesizefromstring(Storage::disk('public')->get($iconPath))[0]);

        // a newer build's icon replaces the one taken from the previous APK
        $this->uploadApk($this->apkWithIcons(['mipmap-xxhdpi-v4' => 144]))->assertOk();

        Storage::disk('public')->assertMissing($iconPath);
        $this->assertSame(144, getimagesizefromstring(Storage::disk('public')->get($this->listing->refresh()->icon_path))[0]);
    }

    public function test_an_icon_the_developer_uploaded_is_kept(): void
    {
        $this->listing->attachMedia(ListingMedia::Icon, UploadedFile::fake()->image('icon.png', 512, 512));
        $iconPath = $this->listing->icon_path;

        $this->uploadApk($this->apkWithIcons(['mipmap-xxxhdpi-v4' => 192]))
            ->assertOk()
            ->assertJsonPath('data.icon_from_apk', false);

        $this->assertSame($iconPath, $this->listing->refresh()->icon_path);
    }

    private function uploadApk(string $apk): TestResponse
    {
        $upload = $this->startUpload('game.apk', strlen($apk))->json('data.id');
        $this->sendChunk($upload, 0, $apk)->assertOk();

        return $this->postJson(route('v1.developer.apps.apk-uploads.complete', [$this->listing, $upload]));
    }

    /**
     * An APK-shaped ZIP with an ic_launcher.png of the given size in each resource directory.
     *
     * @param  array<string, int>  $icons
     */
    private function apkWithIcons(array $icons): string
    {
        $path = tempnam(sys_get_temp_dir(), 'apk');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('classes.dex', 'dex');
        foreach ($icons as $directory => $size) {
            $zip->addFromString("res/{$directory}/ic_launcher.png", UploadedFile::fake()->image('icon.png', $size, $size)->getContent());
        }
        $zip->close();

        $apk = file_get_contents($path);
        unlink($path);

        return $apk;
    }

    private function startUpload(string $filename, int $size): TestResponse
    {
        return $this->postJson(route('v1.developer.apps.apk-uploads.store', $this->listing), [
            'filename' => $filename,
            'size' => $size,
        ]);
    }

    private function sendChunk(string $upload, int $offset, string $bytes): TestResponse
    {
        return $this->post(route('v1.developer.apps.apk-uploads.chunk', [$this->listing, $upload]), [
            'offset' => $offset,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', $bytes),
        ], ['Accept' => 'application/json']);
    }

    private function attachApk(Listing $listing): void
    {
        $apk = "PK\x03\x04".random_bytes(100);
        $upload = $this->startUpload('game.apk', strlen($apk))->json('data.id');
        $this->sendChunk($upload, 0, $apk);
        $this->postJson(route('v1.developer.apps.apk-uploads.complete', [$listing, $upload]))->assertOk();
        $listing->refresh();
    }
}
