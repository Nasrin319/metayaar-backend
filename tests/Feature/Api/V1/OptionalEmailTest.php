<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\Role;
use App\Models\Admin;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OptionalEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signup(array $overrides = []): array
    {
        $this->issueOtp(OtpChannel::Mobile, '09123456789', OtpPurpose::Signup);

        return [
            'name' => 'Jane Doe',
            'mobile' => '09123456789',
            'mobile_code' => '123456',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            ...$overrides,
        ];
    }

    private function memberWithoutEmail(): User
    {
        return User::factory()->create(['email' => null, 'email_verified_at' => null, 'mobile' => '09123456789'])
            ->assignRole(Role::Member->value);
    }

    public function test_a_member_can_sign_up_without_an_email(): void
    {
        foreach ([[], ['email' => ''], ['email' => '   ']] as $i => $email) {
            $mobile = '0912345678'.$i;
            $this->issueOtp(OtpChannel::Mobile, $mobile, OtpPurpose::Signup);

            $this->postJson(route('v1.auth.register', 'member'), [...$this->signup($email), 'mobile' => $mobile])
                ->assertCreated()
                ->assertJsonPath('user.email', null);
        }

        // several accounts without an email don't clash on the unique index
        $this->assertSame(3, User::whereNull('email')->count());
    }

    public function test_a_developer_must_give_an_email_and_a_mobile(): void
    {
        $this->postJson(route('v1.auth.register', 'developer'), $this->signup())
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['email']);

        $this->postJson(route('v1.auth.register', 'developer'), [...$this->signup(['email' => 'dev@example.com']), 'mobile' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile']);

        $this->postJson(route('v1.auth.register', 'developer'), $this->signup(['email' => 'dev@example.com']))
            ->assertCreated()
            ->assertJsonPath('user.email', 'dev@example.com')
            ->assertJsonPath('user.email_verified_at', null);
    }

    public function test_signing_up_sends_nothing_to_the_email(): void
    {
        Notification::fake();

        $this->postJson(route('v1.auth.register', 'developer'), $this->signup(['email' => 'dev@example.com']))->assertCreated();

        Notification::assertNothingSent();
    }

    public function test_an_account_can_sign_in_with_its_mobile_and_password(): void
    {
        $this->memberWithoutEmail();

        $this->postJson(route('v1.auth.login', 'member'), ['mobile' => '+98 912 345 6789', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token']);

        $this->postJson(route('v1.auth.login', 'member'), ['mobile' => '09123456789', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile']);

        $this->postJson(route('v1.auth.login', 'member'), ['password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'mobile']);
    }

    public function test_a_member_without_an_email_cannot_become_a_developer_until_they_add_one(): void
    {
        $member = $this->memberWithoutEmail();
        Sanctum::actingAs($member, ['role:member']);

        $this->postJson(route('v1.me.roles.store', 'developer'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
        $this->assertFalse($member->fresh()->hasRole(Role::Developer->value));

        $this->putJson(route('v1.me.update'), ['name' => 'Jane', 'email' => 'jane@example.com'])->assertOk();
    }

    public function test_staff_cannot_make_an_account_without_an_email_a_developer(): void
    {
        $user = $this->memberWithoutEmail();
        Sanctum::actingAs(Admin::factory()->create()->assignRole('admin'));

        $this->putJson("/api/v1/admin/users/{$user->id}/roles", ['roles' => ['member', 'developer']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['roles']);
        $this->assertFalse($user->fresh()->hasRole(Role::Developer->value));

        $this->putJson("/api/v1/admin/users/{$user->id}/roles", ['roles' => ['member']])->assertOk();
    }

    public function test_only_a_member_can_remove_their_email_and_changing_it_needs_verifying_again(): void
    {
        $member = User::factory()->create(['email' => 'jane@example.com'])->assignRole(Role::Member->value);
        Sanctum::actingAs($member, ['role:member']);

        $this->putJson(route('v1.me.update'), ['name' => 'Jane', 'email' => 'new@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email_verified_at', null);
        $this->putJson(route('v1.me.update'), ['name' => 'Jane', 'email' => null])
            ->assertOk()
            ->assertJsonPath('data.email', null);

        $developer = User::factory()->create()->assignRole(Role::Developer->value);
        Sanctum::actingAs($developer, ['role:developer']);

        $this->putJson(route('v1.me.update'), ['name' => 'Dev', 'email' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_an_account_without_an_email_is_told_to_add_one_before_verifying(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->memberWithoutEmail(), ['role:member']);

        $this->postJson(route('v1.me.verification.send', 'email'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
        $this->postJson(route('v1.me.verification.verify', 'email'), ['code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        Notification::assertNothingSent();
    }

    public function test_the_email_is_verified_with_a_code_from_the_profile(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'jane@example.com'])->assignRole(Role::Member->value);
        Sanctum::actingAs($user, ['role:member']);

        $this->postJson(route('v1.me.verification.send', 'email'))->assertOk();
        $this->issueOtp(OtpChannel::Email, 'jane@example.com', OtpPurpose::Verify, '333333');

        $this->postJson(route('v1.me.verification.verify', 'email'), ['code' => '333333'])->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_staff_find_users_by_mobile(): void
    {
        $this->memberWithoutEmail();
        Sanctum::actingAs(Admin::factory()->create()->assignRole('admin'));

        $this->getJson(route('v1.admin.users.index', ['search' => '0912345']))
            ->assertOk()
            ->assertJsonPath('data.0.mobile', '09123456789');
    }
}
