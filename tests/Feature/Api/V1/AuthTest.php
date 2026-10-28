<?php

namespace Tests\Feature\Api\V1;

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
            'player' => [Role::Player],
            'developer' => [Role::Developer],
        ];
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function staffRoles(): array
    {
        return [
            'moderator' => [Role::Moderator],
            'curator' => [Role::Curator],
            'admin' => [Role::Admin],
        ];
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function allRoles(): array
    {
        return [...self::selfRegisterableRoles(), ...self::staffRoles()];
    }

    #[DataProvider('selfRegisterableRoles')]
    public function test_user_can_register_with_self_registerable_role(Role $role): void
    {
        $response = $this->postJson(route('v1.auth.register', $role->value), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('role', $role->value)
            ->assertJsonPath('user.email', 'jane@example.com')
            ->assertJsonPath('user.roles', [$role->value])
            ->assertJsonStructure(['token', 'token_type', 'role', 'user' => ['id', 'name', 'email', 'roles']]);

        $this->assertTrue(User::firstWhere('email', 'jane@example.com')->hasRole($role->value));
    }

    #[DataProvider('staffRoles')]
    public function test_staff_roles_cannot_self_register(Role $role): void
    {
        $this->postJson(route('v1.auth.register', $role->value), [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
    }

    public function test_register_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson(route('v1.auth.register', 'player'), [
            'email' => 'taken@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'other-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_unknown_role_returns_not_found(): void
    {
        $this->postJson('/api/v1/auth/superuser/login', [
            'email' => 'a@example.com',
            'password' => 'password',
        ])->assertNotFound();
    }

    #[DataProvider('allRoles')]
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

    public function test_user_cannot_login_with_a_role_they_do_not_have(): void
    {
        $player = User::factory()->create()->assignRole(Role::Player->value);

        $this->postJson(route('v1.auth.login', Role::Developer->value), [
            'email' => $player->email,
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertCount(0, $player->tokens);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create()->assignRole(Role::Player->value);

        $this->postJson(route('v1.auth.login', Role::Player->value), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
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
        $user = User::factory()->create()->assignRole(Role::Player->value);
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

    public function test_user_can_change_their_password_and_other_sessions_end(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1'])->assignRole(Role::Player->value);
        $user->createToken('desktop', ['role:player']);
        $token = $user->createToken('website', ['role:player'])->plainTextToken;

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

    public function test_player_can_become_a_developer_without_a_second_account(): void
    {
        $user = User::factory()->create()->assignRole(Role::Player->value);
        $token = $user->createToken('website', ['role:player'])->plainTextToken;

        $response = $this->withToken($token)->postJson(route('v1.me.roles.store', 'developer'))
            ->assertOk()
            ->assertJsonPath('role', 'developer')
            ->assertJsonPath('user.roles', ['player', 'developer']);

        $this->assertCount(1, $user->tokens()->get());
        $this->assertSame(['role:developer'], $user->tokens()->first()->abilities);

        $this->app['auth']->forgetGuards();
        $this->withToken($response->json('token'))->getJson(route('v1.developer.apps.index'))->assertOk();
    }

    public function test_staff_roles_cannot_be_self_assigned(): void
    {
        Sanctum::actingAs($user = User::factory()->create()->assignRole(Role::Player->value));

        $this->postJson(route('v1.me.roles.store', 'admin'))->assertForbidden();
        $this->assertFalse($user->fresh()->hasRole(Role::Admin->value));
    }

    public function test_role_middleware_restricts_role_areas(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Player->value));

        $this->getJson(route('v1.developer.apps.index'))->assertForbidden();
        $this->getJson(route('v1.admin.dashboard'))->assertForbidden();

        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Developer->value));

        $this->getJson(route('v1.developer.apps.index'))->assertOk();
    }
}
