<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('public');
        // every staff role can edit their own profile, not only admins
        $this->admin = Admin::factory()->create(['email' => 'mod@example.com'])->assignRole(StaffRole::Moderator->value);
    }

    public function test_staff_member_updates_their_name_and_email(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson(route('v1.admin.me.update'), ['name' => 'Sara', 'email' => ' Sara@Example.com '])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sara')
            ->assertJsonPath('data.email', 'sara@example.com')
            ->assertJsonPath('data.roles', ['moderator']);
    }

    public function test_email_must_be_unique_among_staff_only(): void
    {
        Admin::factory()->create(['email' => 'taken@example.com']);
        User::factory()->create(['email' => 'website@example.com']);
        Sanctum::actingAs($this->admin);

        $this->putJson(route('v1.admin.me.update'), ['name' => 'Sara', 'email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
        // a website account with the same address is a different account
        $this->putJson(route('v1.admin.me.update'), ['name' => 'Sara', 'email' => 'website@example.com'])->assertOk();
    }

    public function test_staff_member_changes_password_and_other_sessions_end(): void
    {
        $this->admin->createToken('other-browser');
        $current = $this->admin->createToken('admin-panel');

        $this->withToken($current->plainTextToken)
            ->putJson(route('v1.admin.me.password'), [
                'current_password' => 'password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $this->admin->fresh()->password));
        $this->assertSame(['admin-panel'], $this->admin->tokens()->pluck('name')->all());
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->putJson(route('v1.admin.me.password'), [
            'current_password' => 'not-it',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['current_password']);
    }

    public function test_staff_member_uploads_replaces_and_removes_avatar(): void
    {
        Sanctum::actingAs($this->admin);

        $this->post(route('v1.admin.me.avatar.store'), ['avatar' => UploadedFile::fake()->image('me.png', 200, 200)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.avatar_url', fn (string $url): bool => str_contains($url, "avatars/admins/{$this->admin->id}/"));
        $first = $this->admin->fresh()->avatar_path;

        $this->post(route('v1.admin.me.avatar.store'), ['avatar' => UploadedFile::fake()->image('me.jpg', 200, 200)], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertMissing($first);

        $this->deleteJson(route('v1.admin.me.avatar.destroy'))->assertOk()->assertJsonPath('data.avatar_url', null);
        $this->assertNull($this->admin->fresh()->avatar_path);

        $this->post(route('v1.admin.me.avatar.store'), ['avatar' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['avatar']);
    }

    public function test_website_users_cannot_use_the_staff_profile_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson(route('v1.admin.me.update'), ['name' => 'X', 'email' => 'x@example.com'])->assertForbidden();
    }
}
