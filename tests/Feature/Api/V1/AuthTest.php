<?php

namespace Tests\Feature\Api\V1;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function selfRegisterableRoles(): array
    {
        return [
            'member' => [Role::Member],
            'developer' => [Role::Developer],
        ];
    }

    /**
     * Staff roles belong to admin accounts and don't exist on the website.
     *
     * @return array<string, array{string}>
     */
    public static function staffRoles(): array
    {
        return [
            'moderator' => ['moderator'],
            'curator' => ['curator'],
            'admin' => ['admin'],
        ];
    }

    #[DataProvider('selfRegisterableRoles')]
    public function test_user_can_register_with_self_registerable_role(Role $role): void
    {
        $this->issueOtp(OtpChannel::Mobile, '09123456789', OtpPurpose::Signup);

        $response = $this->postJson(route('v1.auth.register', $role->value), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'mobile' => '09123456789',
            'mobile_code' => '123456',
        ]);

        $response->assertCreated()
            ->assertJsonPath('role', $role->value)
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonPath('user.roles', [$role->value])
            ->assertJsonStructure(['token', 'token_type', 'role', 'user' => ['id', 'name', 'email', 'roles']]);

        $this->assertTrue(User::firstWhere('email', 'jane@example.com')->hasRole($role->value));
    }

    #[DataProvider('selfRegisterableRoles')]
    public function test_user_can_register_as_an_organization(Role $role): void
    {
        $this->issueOtp(OtpChannel::Mobile, '09123456789', OtpPurpose::Signup);

        $response = $this->postJson(route('v1.auth.register', $role->value), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'mobile' => '09123456789',
            'mobile_code' => '123456',
            'account_type' => 'organization',
            'organization_name' => 'Acme VR',
            'organization_national_id' => '10101234567',
            'organization_phone' => '02112345678',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.account_type', 'organization')
            ->assertJsonPath('user.organization.name', 'Acme VR')
            ->assertJsonPath('user.organization.national_id', '10101234567');

        $user = User::firstWhere('email', 'jane@example.com');
        $this->assertTrue($user->isOrganization());
        $this->assertTrue($user->hasRole($role->value));
        $this->assertSame('Acme VR', $user->organization->name);
    }

    public function test_individual_registration_ignores_organization_fields(): void
    {
        $this->issueOtp(OtpChannel::Mobile, '09123456789', OtpPurpose::Signup);

        $this->postJson(route('v1.auth.register', 'member'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'mobile' => '09123456789',
            'mobile_code' => '123456',
            'organization_name' => 'Acme VR',
        ])->assertCreated()
            ->assertJsonPath('user.account_type', 'individual')
            ->assertJsonPath('user.organization', null);

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_organization_registration_validates_organization_fields(): void
    {
        $this->postJson(route('v1.auth.register', 'member'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'account_type' => 'organization',
            'organization_national_id' => '12ab',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['organization_name', 'organization_national_id']);

        $this->postJson(route('v1.auth.register', 'member'), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'account_type' => 'company',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['account_type']);

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_profile_includes_organization_details(): void
    {
        $user = User::factory()->organization()->create()->assignRole(Role::Member->value);
        Sanctum::actingAs($user, ['role:member']);

        $this->getJson(route('v1.me'))
            ->assertOk()
            ->assertJsonPath('data.account_type', 'organization')
            ->assertJsonPath('data.organization.name', $user->organization->name);
    }

    #[DataProvider('staffRoles')]
    public function test_staff_roles_cannot_register_or_log_in_on_the_website(string $role): void
    {
        $this->postJson("/api/v1/auth/{$role}/register", [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertNotFound();
        $this->postJson("/api/v1/auth/{$role}/login", ['email' => 'jane@example.com', 'password' => 'secret-password'])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_register_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson(route('v1.auth.register', 'member'), [
            'email' => 'taken@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'other-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'mobile']);
    }

    public function test_register_needs_a_mobile_and_its_code(): void
    {
        $payload = [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ];

        $this->postJson(route('v1.auth.register', 'member'), $payload)
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['mobile', 'mobile_code']);

        $this->postJson(route('v1.auth.register', 'member'), [...$payload, 'mobile' => '09123456789'])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['mobile_code']);

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_logging_in_with_the_wrong_role_names_the_accounts_role_only_after_the_right_password(): void
    {
        $user = User::factory()->create()->assignRole(Role::Developer->value);

        $this->postJson(route('v1.auth.login', 'member'), ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'This account does not have the member role. Its roles: developer.');

        $wrongPassword = $this->postJson(route('v1.auth.login', 'member'), ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable();
        $this->assertStringNotContainsString('developer', $wrongPassword->json('errors.email.0'));
    }

    public function test_code_login_with_the_wrong_role_names_the_accounts_role_and_keeps_the_code(): void
    {
        $user = User::factory()->create()->assignRole(Role::Developer->value);
        $this->issueOtp(OtpChannel::Email, $user->email, OtpPurpose::Login);
        $payload = ['channel' => 'email', 'destination' => $user->email, 'code' => '123456'];

        $this->postJson(route('v1.auth.login.code', 'member'), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.destination.0', 'This account does not have the member role. Its roles: developer.');

        // the same code still works once the right role is chosen
        $this->postJson(route('v1.auth.login.code', 'developer'), $payload)->assertOk();
    }

    public function test_unknown_role_returns_not_found(): void
    {
        $this->postJson('/api/v1/auth/superuser/login', [
            'email' => 'a@example.com',
            'password' => 'password',
        ])->assertNotFound();
    }

    #[DataProvider('selfRegisterableRoles')]
    public function test_user_can_login_with_their_role(Role $role): void
    {
        $user = User::factory()->create()->assignRole($role->value);

        $this->postJson(route('v1.auth.login', $role->value), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('role', $role->value)
            ->assertJsonPath('user.id', $user->id);

        $this->assertTrue($user->tokens()->first()->can("role:{$role->value}"));
    }

    #[DataProvider('selfRegisterableRoles')]
    public function test_login_token_expires_after_twelve_hours(Role $role): void
    {
        $this->freezeSecond();
        $user = User::factory()->create()->assignRole($role->value);

        $token = $this->postJson(route('v1.auth.login', $role->value), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('expires_at', now()->addHours(12)->toIso8601String())
            ->json('token');

        $this->travel(12)->hours();
        $this->travel(1)->minute();

        $this->withToken($token)->getJson(route('v1.me'))->assertUnauthorized();
    }

    public function test_login_ignores_email_case_and_spaces(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com'])->assignRole(Role::Member->value);

        $this->postJson(route('v1.auth.login', 'member'), [
            'email' => ' Jane@Example.com ',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_user_cannot_login_with_a_role_they_do_not_have(): void
    {
        $member = User::factory()->create()->assignRole(Role::Member->value);

        $this->postJson(route('v1.auth.login', Role::Developer->value), [
            'email' => $member->email,
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertCount(0, $member->tokens);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create()->assignRole(Role::Member->value);

        $this->postJson(route('v1.auth.login', Role::Member->value), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'ایمیل یا رمز عبور اشتباه است.']);
    }

    public function test_login_validation_errors_are_in_persian(): void
    {
        $this->postJson(route('v1.auth.login', Role::Member->value), [
            'email' => 'not-an-email',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email' => 'ایمیل باید یک ایمیل معتبر باشد.',
                'password' => 'وارد کردن رمز عبور الزامی است.',
            ]);
    }

    public function test_authenticated_user_can_fetch_profile_and_logout(): void
    {
        $user = User::factory()->create()->assignRole(Role::Developer->value);
        $token = $user->createToken('test', ['role:developer'])->plainTextToken;

        $this->withToken($token)->getJson(route('v1.me'))
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.roles', ['developer']);

        $this->withToken($token)->postJson(route('v1.auth.logout'))->assertOk();

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_user_can_update_their_profile(): void
    {
        $user = User::factory()->create()->assignRole(Role::Member->value);
        $taken = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson(route('v1.me.update'), ['name' => 'Sara', 'email' => 'sara@example.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sara')
            ->assertJsonPath('data.email', 'sara@example.com');

        $this->assertNull($user->fresh()->email_verified_at);

        $this->putJson(route('v1.me.update'), ['name' => 'Sara', 'email' => $taken->email])
            ->assertJsonValidationErrors('email');
    }

    public function test_individual_can_switch_to_an_organization_account(): void
    {
        $user = User::factory()->create()->assignRole(Role::Member->value);
        Sanctum::actingAs($user);

        $this->putJson(route('v1.me.organization'), ['name' => 'Acme VR', 'national_id' => '10101234567', 'phone' => '02112345678'])
            ->assertOk()
            ->assertJsonPath('data.account_type', 'organization')
            ->assertJsonPath('data.organization.name', 'Acme VR')
            ->assertJsonPath('data.organization.national_id', '10101234567');

        $user->refresh();
        $this->assertTrue($user->isOrganization());
        $this->assertTrue($user->hasRole(Role::Member->value));
    }

    public function test_organization_can_update_its_details(): void
    {
        $user = User::factory()->organization()->create()->assignRole(Role::Developer->value);
        Sanctum::actingAs($user);

        $this->putJson(route('v1.me.organization'), ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.organization.name', 'New Name')
            ->assertJsonPath('data.organization.national_id', null);

        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_organization_details_are_validated(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Member->value));

        $this->putJson(route('v1.me.organization'), ['national_id' => '12ab'])
            ->assertJsonValidationErrors(['name', 'national_id']);

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_user_can_change_their_password_and_other_sessions_end(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1'])->assignRole(Role::Member->value);
        $user->createToken('desktop', ['role:member']);
        $token = $user->createToken('website', ['role:member'])->plainTextToken;

        $this->withToken($token)->putJson(route('v1.me.password'), [
            'current_password' => 'wrong',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertJsonValidationErrors('current_password');

        $this->withToken($token)->putJson(route('v1.me.password'), [
            'current_password' => 'old-password-1',
            'password' => 'new-password-1',
            'password_confirmation' => 'new-password-1',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('new-password-1', $user->password));
        $this->assertSame(['website'], $user->tokens()->pluck('name')->all());
    }

    public function test_member_can_become_a_developer_without_a_second_account(): void
    {
        $user = User::factory()->create()->assignRole(Role::Member->value);
        $token = $user->createToken('website', ['role:member'])->plainTextToken;

        $response = $this->withToken($token)->postJson(route('v1.me.roles.store', 'developer'))
            ->assertOk()
            ->assertJsonPath('role', 'developer')
            ->assertJsonPath('user.roles', ['member', 'developer']);

        $this->assertCount(1, $user->tokens()->get());
        $this->assertSame(['role:developer'], $user->tokens()->first()->abilities);

        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('token'))->getJson(route('v1.developer.apps.index'))->assertOk();
    }

    public function test_staff_roles_cannot_be_self_assigned(): void
    {
        Sanctum::actingAs($user = User::factory()->create()->assignRole(Role::Member->value));

        $this->postJson('/api/v1/me/roles/admin')->assertNotFound();
        $this->assertSame(['member'], $user->fresh()->getRoleNames()->all());
    }

    public function test_role_middleware_restricts_role_areas(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Member->value));

        $this->getJson(route('v1.developer.apps.index'))->assertForbidden();
        $this->getJson(route('v1.admin.dashboard'))->assertForbidden();

        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Developer->value));

        $this->getJson(route('v1.developer.apps.index'))->assertOk();
    }
}
