<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\ListingStatus;
use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\Role;
use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\Comment;
use App\Models\Listing;
use App\Models\Purchase;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_disabling_signs_the_user_out_everywhere(): void
    {
        $user = User::factory()->create()->assignRole(Role::Member->value);
        $token = $user->createToken('website', ['role:member'])->plainTextToken;
        $this->actingAsStaff(StaffRole::Admin);

        $this->postJson(route('v1.admin.users.disable', $user))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.disabled_at', fn ($value) => $value !== null);

        $this->assertTrue($user->fresh()->isDisabled());
        $this->assertCount(0, $user->tokens()->get());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(route('v1.me'))->assertUnauthorized();
    }

    public function test_a_disabled_user_cannot_sign_in_with_a_password_or_a_code(): void
    {
        $user = User::factory()->create(['disabled_at' => now()])->assignRole(Role::Member->value);

        $this->postJson(route('v1.auth.login', 'member'), ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => User::DISABLED_MESSAGE]);

        // a valid code is still refused
        $this->issueOtp(OtpChannel::Email, $user->email, OtpPurpose::Login);
        $this->postJson(route('v1.auth.login.code', 'member'), ['channel' => 'email', 'destination' => $user->email, 'code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['destination' => User::DISABLED_MESSAGE]);

        $this->assertCount(0, $user->tokens()->get());
    }

    public function test_a_wrong_password_does_not_reveal_that_the_account_is_disabled(): void
    {
        $user = User::factory()->create(['disabled_at' => now()])->assignRole(Role::Member->value);

        $this->postJson(route('v1.auth.login', 'member'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertJsonValidationErrors(['email' => 'ایمیل یا رمز عبور اشتباه است.']);
    }

    public function test_a_disabled_token_is_refused_even_if_it_survived(): void
    {
        $user = User::factory()->create(['disabled_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson(route('v1.me'))
            ->assertUnauthorized()
            ->assertJsonPath('message', User::DISABLED_MESSAGE);
    }

    public function test_enabling_lets_the_user_sign_in_again(): void
    {
        $user = User::factory()->create(['disabled_at' => now()])->assignRole(Role::Member->value);
        $this->actingAsStaff(StaffRole::Admin);

        $this->postJson(route('v1.admin.users.enable', $user))->assertOk()->assertJsonPath('data.disabled_at', null);

        $this->postJson(route('v1.auth.login', 'member'), ['email' => $user->email, 'password' => 'password'])->assertOk();
    }

    public function test_deleting_an_account_without_apps_or_purchases(): void
    {
        $user = User::factory()->create();
        $comment = Comment::factory()->for($user)->create();
        $user->createToken('website');
        $this->actingAsStaff(StaffRole::Admin);

        $this->deleteJson(route('v1.admin.users.destroy', $user))->assertOk()->assertJsonPath('message', 'حساب کاربر حذف شد.');

        $this->assertModelMissing($user);
        $this->assertModelMissing($comment);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_accounts_with_apps_or_purchases_are_disabled_instead_of_deleted(): void
    {
        $developer = Listing::factory()->status(ListingStatus::Published)->create()->developer;
        $buyer = Purchase::factory()->create()->user;
        $this->actingAsStaff(StaffRole::Admin);

        foreach ([$developer, $buyer] as $user) {
            $this->deleteJson(route('v1.admin.users.destroy', $user))
                ->assertConflict()
                ->assertJsonPath('message', 'این کاربر اپ یا خرید ثبت‌شده دارد و با حذف آن سوابق فروش هم پاک می‌شود. به‌جای حذف، حساب را غیرفعال کنید.');
            $this->assertModelExists($user);
        }
    }

    public function test_the_list_shows_status_and_what_the_account_owns(): void
    {
        $active = User::factory()->create(['created_at' => now()->subDay()]);
        $disabled = User::factory()->create(['disabled_at' => now()]);
        Listing::factory()->for($disabled, 'developer')->create();
        $this->actingAsStaff(StaffRole::Admin);

        $this->getJson(route('v1.admin.users.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $disabled->id)
            ->assertJsonPath('data.0.listings_count', 1)
            ->assertJsonPath('data.0.purchases_count', 0)
            ->assertJsonPath('data.1.id', $active->id)
            ->assertJsonPath('data.1.disabled_at', null);

        $this->getJson(route('v1.admin.users.index', ['status' => 'disabled']))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $disabled->id);
        $this->getJson(route('v1.admin.users.index', ['status' => 'active']))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    }

    public function test_only_staff_who_manage_users_can_do_this(): void
    {
        $user = User::factory()->create();
        $this->actingAsStaff(StaffRole::Moderator);

        $this->postJson(route('v1.admin.users.disable', $user))->assertForbidden();
        $this->deleteJson(route('v1.admin.users.destroy', $user))->assertForbidden();
        $this->assertFalse($user->fresh()->isDisabled());
    }

    private function actingAsStaff(StaffRole $role): Admin
    {
        $admin = Admin::factory()->create()->assignRole($role->value);
        Sanctum::actingAs($admin);

        return $admin;
    }
}
