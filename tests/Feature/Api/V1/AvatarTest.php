<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    public function test_user_can_upload_replace_and_remove_their_avatar(): void
    {
        $user = User::factory()->create()->assignRole(Role::Member->value);
        Sanctum::actingAs($user, ['role:member']);

        $this->post(route('v1.me.avatar.store'), ['avatar' => UploadedFile::fake()->image('me.png', 256, 256)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.avatar_url', fn (string $url): bool => str_contains($url, "avatars/{$user->id}/"));
        $first = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($first);

        $this->post(route('v1.me.avatar.store'), ['avatar' => UploadedFile::fake()->image('me.jpg', 256, 256)], ['Accept' => 'application/json'])
            ->assertOk();
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($user->fresh()->avatar_path);

        $this->deleteJson(route('v1.me.avatar.destroy'))->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_avatar_must_be_a_small_image(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Member->value), ['role:member']);

        $this->post(route('v1.me.avatar.store'), ['avatar' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['avatar']);
        $this->post(route('v1.me.avatar.store'), ['avatar' => UploadedFile::fake()->image('big.png', 256, 256)->size(3000)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['avatar']);
        $this->post(route('v1.me.avatar.store'), ['avatar' => UploadedFile::fake()->image('tiny.png', 20, 20)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['avatar']);
    }
}
