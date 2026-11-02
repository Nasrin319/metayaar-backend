<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Role;
use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\Listing;
use App\Models\Setting;
use App\Models\User;
use App\Support\Sales;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->developer = User::factory()->create()->assignRole(Role::Developer->value);
    }

    private function paidListing(int $price = 100000, ?int $commission = null): Listing
    {
        return Listing::factory()->for($this->developer, 'developer')->create(['price' => $price])
            ->forceFill(['commission_percent' => $commission]);
    }

    public function test_a_sale_credits_the_developer_minus_the_default_commission(): void
    {
        $listing = $this->paidListing(100000);

        $purchase = app(Sales::class)->record(User::factory()->create(), $listing);

        $this->assertSame(20, $purchase->commission_percent);
        $this->assertSame(20000, $purchase->commission_amount);
        $this->assertSame(80000, $purchase->developer_amount);
        $this->assertSame(80000, $this->developer->fresh()->wallet_balance);
        $this->assertDatabaseHas('wallet_transactions', ['user_id' => $this->developer->id, 'amount' => 80000, 'balance_after' => 80000, 'purchase_id' => $purchase->id]);
    }

    public function test_a_listing_commission_overrides_the_default(): void
    {
        Setting::put(['default_commission_percent' => 30]);
        $own = $this->paidListing(50000, 10);
        $own->save();
        $default = $this->paidListing(50000);

        app(Sales::class)->record(User::factory()->create(), $own);
        app(Sales::class)->record(User::factory()->create(), $default);

        // 45000 + 35000
        $this->assertSame(80000, $this->developer->fresh()->wallet_balance);
    }

    public function test_free_listings_cannot_be_sold(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(Sales::class)->record(User::factory()->create(), $this->paidListing(0));
    }

    public function test_developer_sees_their_wallet(): void
    {
        $listing = $this->paidListing(100000);
        app(Sales::class)->record(User::factory()->create(), $listing);
        app(Sales::class)->record(User::factory()->create(), $listing);
        Sanctum::actingAs($this->developer->fresh());

        $this->getJson(route('v1.developer.wallet'))
            ->assertOk()
            ->assertJsonPath('balance', 160000)
            ->assertJsonPath('totals.sales_count', 2)
            ->assertJsonPath('totals.gross', 200000)
            ->assertJsonPath('totals.commission', 40000)
            ->assertJsonPath('data.0.listing.title', $listing->title)
            ->assertJsonPath('data.0.balance_after', 160000);
    }

    public function test_developer_sees_the_commission_of_their_listing(): void
    {
        $listing = $this->paidListing();
        Sanctum::actingAs($this->developer);

        $this->getJson(route('v1.developer.apps.show', $listing))->assertJsonPath('data.commission_percent', 20);
    }

    public function test_finance_admins_set_the_default_and_per_listing_commission(): void
    {
        $listing = $this->paidListing();
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Admin->value));

        $this->putJson(route('v1.admin.finance.commission'), ['default_commission_percent' => 15])
            ->assertOk()
            ->assertJsonPath('data.default_commission_percent', 15);

        $this->putJson(route('v1.admin.listings.commission', $listing), ['commission_percent' => 5])
            ->assertOk()
            ->assertJsonPath('data.commission_percent', 5)
            ->assertJsonPath('data.commission_override', 5);

        $this->putJson(route('v1.admin.listings.commission', $listing), ['commission_percent' => null])
            ->assertOk()
            ->assertJsonPath('data.commission_percent', 15);

        $this->putJson(route('v1.admin.finance.commission'), ['default_commission_percent' => 101])
            ->assertJsonValidationErrors('default_commission_percent');
    }

    public function test_finance_overview_sums_the_sales(): void
    {
        app(Sales::class)->record(User::factory()->create(), $this->paidListing(100000));
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Admin->value));

        $this->getJson(route('v1.admin.finance.show'))
            ->assertOk()
            ->assertJsonPath('data.totals.gross', 100000)
            ->assertJsonPath('data.totals.commission', 20000)
            ->assertJsonPath('data.totals.wallet_balances', 80000)
            ->assertJsonPath('data.developers.0.id', $this->developer->id);
    }

    public function test_staff_without_the_finance_permission_cannot_see_it(): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole(StaffRole::Moderator->value));

        $this->getJson(route('v1.admin.finance.show'))->assertForbidden();
        $this->putJson(route('v1.admin.listings.commission', $this->paidListing()), ['commission_percent' => 1])->assertForbidden();
    }
}
