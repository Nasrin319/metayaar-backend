<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\OtpChannel;
use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\OtpLockout;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function actingAsStaff(StaffRole $role): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole($role->value));
    }

    public function test_admins_see_and_change_the_otp_lockout_settings(): void
    {
        $this->actingAsStaff(StaffRole::Admin);

        $this->getJson(route('v1.admin.settings.show'))
            ->assertOk()
            ->assertJsonPath('data.otp_max_sends', 5)
            ->assertJsonPath('data.otp_lockout_hours', 5);

        $this->putJson(route('v1.admin.settings.update'), ['otp_max_sends' => 3, 'otp_lockout_hours' => 24])
            ->assertOk()
            ->assertJsonPath('data.otp_max_sends', 3)
            ->assertJsonPath('data.otp_lockout_hours', 24);

        $this->getJson(route('v1.admin.settings.show'))->assertJsonPath('data.otp_lockout_hours', 24);
    }

    public function test_settings_are_validated(): void
    {
        $this->actingAsStaff(StaffRole::Admin);

        $this->putJson(route('v1.admin.settings.update'), ['otp_max_sends' => 0, 'otp_lockout_hours' => 'x'])
            ->assertJsonValidationErrors(['otp_max_sends', 'otp_lockout_hours']);
    }

    public function test_staff_without_the_permission_cannot_change_settings(): void
    {
        $this->actingAsStaff(StaffRole::Moderator);

        $this->getJson(route('v1.admin.settings.show'))->assertForbidden();
        $this->putJson(route('v1.admin.settings.update'), ['otp_max_sends' => 3])->assertForbidden();
    }

    public function test_admins_see_and_release_locked_out_destinations(): void
    {
        $this->actingAsStaff(StaffRole::Admin);
        $locked = OtpLockout::create(['channel' => OtpChannel::Mobile, 'destination' => '09123456789', 'sends' => 5, 'locked_until' => now()->addHours(2)]);
        OtpLockout::create(['channel' => OtpChannel::Mobile, 'destination' => '09120000000', 'sends' => 2]);

        $this->getJson(route('v1.admin.otp-lockouts.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.destination', '09123456789');

        $this->deleteJson(route('v1.admin.otp-lockouts.destroy', $locked))->assertNoContent();
        $this->assertModelMissing($locked);
    }
}
