<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\AdminLogin;
use App\Support\Captcha;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminLoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Admin::factory()->create(['email' => 'mod@metayaar.test']);
    }

    /**
     * @param  array<string, string>  $overrides
     * @param  array<string, string>  $headers
     */
    private function login(array $overrides = [], array $headers = []): TestResponse
    {
        return $this->postJson(route('v1.admin.auth.login'), [
            'email' => 'mod@metayaar.test',
            'password' => 'password',
            'captcha_token' => Captcha::issue('2 + 3', 5)['token'],
            'captcha_answer' => '5',
            ...$overrides,
        ], $headers);
    }

    public function test_the_captcha_endpoint_gives_the_question_only_as_an_image(): void
    {
        $data = $this->getJson(route('v1.admin.auth.captcha'))->assertOk()->json('data');

        $this->assertSame(['image', 'token'], array_keys($data));
        $this->assertStringStartsWith('data:image/png;base64,', $data['image']);
        $this->assertSame('image/png', getimagesizefromstring(base64_decode(substr($data['image'], 22)))['mime']);
        $this->assertStringNotContainsString('answer', $data['token']);
    }

    public function test_a_missing_or_wrong_answer_refuses_even_the_right_password(): void
    {
        $this->postJson(route('v1.admin.auth.login'), ['email' => 'mod@metayaar.test', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['captcha_token', 'captcha_answer']);

        $this->login(['captcha_answer' => '6'])->assertUnprocessable()->assertJsonValidationErrors('captcha_answer');
        $this->login(['captcha_token' => 'forged'])->assertUnprocessable()->assertJsonValidationErrors('captcha_answer');

        $this->assertSame(0, Admin::firstWhere('email', 'mod@metayaar.test')->tokens()->count());
        $this->assertDatabaseCount('admin_logins', 0);
    }

    public function test_a_question_can_be_used_once_and_expires(): void
    {
        $token = Captcha::issue('2 + 3', 5)['token'];
        $this->login(['captcha_token' => $token])->assertOk();
        $this->login(['captcha_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('captcha_answer');

        $old = Captcha::issue('2 + 3', 5)['token'];
        $this->travel(6)->minutes();
        $this->login(['captcha_token' => $old])->assertUnprocessable()->assertJsonValidationErrors('captcha_answer');
    }

    public function test_an_answer_typed_in_persian_digits_is_accepted(): void
    {
        $this->login(['captcha_answer' => '۵'])->assertOk();
    }

    public function test_attempts_are_stored_with_the_address(): void
    {
        $this->login(['password' => 'wrong'], ['User-Agent' => 'Browser/1'])->assertUnprocessable();
        $this->login(['email' => 'nobody@metayaar.test'])->assertUnprocessable();
        $this->login()->assertOk();

        $rows = AdminLogin::orderBy('id')->get();
        $this->assertSame([false, false, true], $rows->pluck('succeeded')->all());
        $this->assertSame('127.0.0.1', $rows[0]->ip_address);
        $this->assertSame('Browser/1', $rows[0]->user_agent);
        $this->assertNotNull($rows[0]->admin_id);
        $this->assertNull($rows[1]->admin_id);
        $this->assertSame('nobody@metayaar.test', $rows[1]->email);
    }

    public function test_only_the_panel_with_the_key_can_pass_the_visitors_address(): void
    {
        config(['services.internal_api_key' => 'secret-key']);
        $forwarded = ['X-Client-IP' => '5.6.7.8', 'X-Client-User-Agent' => 'Visitor/1'];

        $this->login([], $forwarded)->assertOk();
        $this->login([], [...$forwarded, 'X-Internal-Key' => 'wrong'])->assertOk();
        $this->login([], [...$forwarded, 'X-Internal-Key' => 'secret-key'])->assertOk();

        $rows = AdminLogin::orderBy('id')->get();
        $this->assertSame(['127.0.0.1', '127.0.0.1', '5.6.7.8'], $rows->pluck('ip_address')->all());
        $this->assertSame('Visitor/1', $rows[2]->user_agent);

        // with no key configured, nothing is trusted, not even an empty key
        config(['services.internal_api_key' => null]);
        $this->login([], [...$forwarded, 'X-Internal-Key' => ''])->assertOk();
        $this->assertSame('127.0.0.1', AdminLogin::latest('id')->first()->ip_address);
    }

    public function test_the_history_needs_the_staff_permission_and_old_rows_are_pruned(): void
    {
        $this->login()->assertOk();

        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Moderator->value));
        $this->getJson(route('v1.admin.login-history.index'))->assertForbidden();

        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Admin->value));
        $this->getJson(route('v1.admin.login-history.index'))
            ->assertOk()
            ->assertJsonPath('data.0.email', 'mod@metayaar.test')
            ->assertJsonPath('data.0.ip_address', '127.0.0.1')
            ->assertJsonPath('data.0.succeeded', true);

        $this->travel(91)->days();
        $this->artisan('model:prune', ['--model' => [AdminLogin::class]]);
        $this->assertDatabaseCount('admin_logins', 0);
    }
}
