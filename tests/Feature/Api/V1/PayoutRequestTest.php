<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Role;
use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\Listing;
use App\Models\PayoutAccount;
use App\Models\PayoutRequest;
use App\Models\Setting;
use App\Models\User;
use App\Support\Sales;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayoutRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->admin = Admin::factory()->create()->assignRole(StaffRole::Admin->value);
        $this->developer = User::factory()->create()->assignRole(Role::Developer->value);
        PayoutAccount::factory()->for($this->developer)->create(['card_number' => '6037990000001234']);
        Sanctum::actingAs($this->developer, ['role:developer']);
    }

    /** A 100,000 toman sale at the default 20% commission: 80,000 for the developer. */
    private function sale(): void
    {
        $listing = Listing::factory()->for($this->developer, 'developer')->create(['price' => 100000]);
        app(Sales::class)->record(User::factory()->create(), $listing);
        $this->developer->refresh();
    }

    private function finance(): Admin
    {
        Sanctum::actingAs($this->admin, ['admin'], 'sanctum');

        return $this->admin;
    }

    private function asDeveloper(): void
    {
        Sanctum::actingAs($this->developer->refresh(), ['role:developer']);
    }

    public function test_a_sale_can_only_be_requested_after_the_hold_days_in_settings(): void
    {
        Setting::put(['payout_hold_days' => 5]);
        $this->sale();

        $this->getJson('/api/v1/developer/wallet')->assertOk()
            ->assertJsonPath('balance', 80000)
            ->assertJsonPath('payout', ['balance' => 80000, 'held' => 80000, 'pending' => 0, 'available' => 0, 'hold_days' => 5]);
        $this->postJson('/api/v1/developer/payout-requests', ['amount' => 1000])->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->travel(5)->days();
        $this->travel(1)->minutes();

        $this->getJson('/api/v1/developer/wallet')->assertJsonPath('payout.available', 80000);
        $this->postJson('/api/v1/developer/payout-requests', ['amount' => 80000])->assertCreated()->assertJsonPath('data.status', 'pending');
    }

    public function test_hold_days_of_zero_makes_sales_available_straight_away(): void
    {
        Setting::put(['payout_hold_days' => 0]);
        $this->sale();

        $this->getJson('/api/v1/developer/wallet')->assertJsonPath('payout.available', 80000);
    }

    public function test_a_request_needs_bank_details_and_cannot_exceed_what_is_available(): void
    {
        Setting::put(['payout_hold_days' => 0]);
        $this->sale();

        $this->postJson('/api/v1/developer/payout-requests', ['amount' => 80001])->assertUnprocessable();
        $this->postJson('/api/v1/developer/payout-requests', ['amount' => 0])->assertUnprocessable();

        $this->developer->payoutAccount->delete();
        $this->postJson('/api/v1/developer/payout-requests', ['amount' => 100])->assertUnprocessable();
    }

    public function test_only_one_request_can_be_open_and_it_reserves_the_money(): void
    {
        Setting::put(['payout_hold_days' => 0]);
        $this->sale();

        $this->postJson('/api/v1/developer/payout-requests', ['amount' => 30000])->assertCreated();

        $this->postJson('/api/v1/developer/payout-requests', ['amount' => 10000])->assertUnprocessable();
        $this->getJson('/api/v1/developer/wallet')
            ->assertJsonPath('balance', 80000)
            ->assertJsonPath('payout.pending', 30000)
            ->assertJsonPath('payout.available', 50000);
    }

    public function test_paying_takes_money_from_the_wallet_tells_the_developer_and_shows_in_their_list(): void
    {
        Setting::put(['payout_hold_days' => 0]);
        $this->sale();
        $id = $this->postJson('/api/v1/developer/payout-requests', ['amount' => 30000])->json('data.id');

        $admin = $this->finance();
        $this->assertSame(1, $admin->notifications()->where('data->payout_request_id', $id)->count());
        $this->getJson('/api/v1/admin/payout-requests')->assertOk()
            ->assertJsonPath('pending_count', 1)
            ->assertJsonPath('data.0.card_number', '6037990000001234');
        $this->postJson("/api/v1/admin/payout-requests/{$id}/pay", ['reference' => 'TR-99'])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->assertSame(50000, $this->developer->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $this->developer->id, 'type' => 'payout', 'amount' => -30000, 'balance_after' => 50000]);
        $this->assertSame(0, $admin->unreadNotifications()->where('data->payout_request_id', $id)->count());

        $this->asDeveloper();
        $this->getJson('/api/v1/developer/payout-requests')->assertOk()
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.reference', 'TR-99')
            ->assertJsonPath('data.0.destination', '1234')
            ->assertJsonMissingPath('data.0.card_number');
        $this->getJson('/api/v1/developer/notifications')->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.kind', 'payout_paid')
            ->assertJsonPath('data.0.url', '/developer/wallet#payout-requests');
        $this->getJson('/api/v1/developer/wallet')->assertJsonPath('payout.pending', 0)->assertJsonPath('payout.available', 50000);
    }

    public function test_a_request_cannot_be_paid_twice(): void
    {
        Setting::put(['payout_hold_days' => 0]);
        $this->sale();
        $id = $this->postJson('/api/v1/developer/payout-requests', ['amount' => 30000])->json('data.id');

        $this->finance();
        $this->postJson("/api/v1/admin/payout-requests/{$id}/pay")->assertOk();
        $this->postJson("/api/v1/admin/payout-requests/{$id}/pay")->assertConflict();
        $this->postJson("/api/v1/admin/payout-requests/{$id}/reject", ['reason' => 'x'])->assertConflict();

        $this->assertSame(50000, $this->developer->fresh()->wallet_balance);
    }

    public function test_rejecting_leaves_the_wallet_alone_and_says_why(): void
    {
        Setting::put(['payout_hold_days' => 0]);
        $this->sale();
        $id = $this->postJson('/api/v1/developer/payout-requests', ['amount' => 30000])->json('data.id');

        $this->finance();
        $this->postJson("/api/v1/admin/payout-requests/{$id}/reject")->assertUnprocessable();
        $this->postJson("/api/v1/admin/payout-requests/{$id}/reject", ['reason' => 'شماره کارت اشتباه است'])->assertOk();

        $this->assertSame(80000, $this->developer->fresh()->wallet_balance);

        $this->asDeveloper();
        $this->getJson('/api/v1/developer/payout-requests')->assertJsonPath('data.0.rejection_reason', 'شماره کارت اشتباه است');
        $this->getJson('/api/v1/developer/notifications')->assertJsonPath('data.0.kind', 'payout_rejected');
        $this->getJson('/api/v1/developer/wallet')->assertJsonPath('payout.available', 80000);
    }

    public function test_a_request_keeps_the_bank_details_it_was_made_with(): void
    {
        Setting::put(['payout_hold_days' => 0]);
        $this->sale();
        $id = $this->postJson('/api/v1/developer/payout-requests', ['amount' => 100])->json('data.id');

        $this->developer->payoutAccount->update(['card_number' => '6037990000009999']);

        $this->assertSame('6037990000001234', PayoutRequest::find($id)->card_number);
    }

    public function test_staff_without_the_finance_permission_and_other_developers_cannot_reach_requests(): void
    {
        Setting::put(['payout_hold_days' => 0]);
        $this->sale();
        $id = $this->postJson('/api/v1/developer/payout-requests', ['amount' => 100])->json('data.id');

        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Moderator->value), ['admin'], 'sanctum');
        $this->getJson('/api/v1/admin/payout-requests')->assertForbidden();
        $this->postJson("/api/v1/admin/payout-requests/{$id}/pay")->assertForbidden();

        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Developer->value), ['role:developer']);
        $this->getJson('/api/v1/developer/payout-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/admin/payout-requests/{$id}/pay")->assertForbidden();

        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Member->value), ['role:member']);
        $this->getJson('/api/v1/developer/notifications')->assertForbidden();
        $this->postJson('/api/v1/developer/payout-requests', ['amount' => 1])->assertForbidden();

        $this->assertSame(80000, $this->developer->fresh()->wallet_balance);
    }

    public function test_the_hold_days_setting_is_validated(): void
    {
        $this->finance();

        $this->putJson('/api/v1/admin/settings', ['payout_hold_days' => 7])->assertOk()->assertJsonPath('data.payout_hold_days', 7);
        $this->putJson('/api/v1/admin/settings', ['payout_hold_days' => 0])->assertOk();
        $this->putJson('/api/v1/admin/settings', ['payout_hold_days' => 91])->assertUnprocessable();
    }
}
