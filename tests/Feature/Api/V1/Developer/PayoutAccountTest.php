<?php

namespace Tests\Feature\Api\V1\Developer;

use App\Enums\Role;
use App\Models\Listing;
use App\Models\PayoutAccount;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayoutAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->developer = User::factory()->create()->assignRole(Role::Developer->value);
        Sanctum::actingAs($this->developer);
    }

    /**
     * @return array<string, string>
     */
    private function details(array $overrides = []): array
    {
        return [
            'holder_name' => 'Jane Doe',
            'national_id' => '۰۴۹۹۳۷۰۸۹۹',
            'bank_name' => 'بانک ملی',
            'sheba' => 'ir06 2960 0000 0010 0324 2000 01',
            'card_number' => '',
            'postal_code' => '1234567890',
            'address' => 'Tehran',
            ...$overrides,
        ];
    }

    public function test_developer_without_payout_details_gets_null(): void
    {
        $this->getJson(route('v1.developer.payout-account.show'))->assertOk()->assertJsonPath('data', null);
    }

    public function test_developer_can_save_and_change_payout_details(): void
    {
        $this->putJson(route('v1.developer.payout-account.update'), $this->details())
            ->assertOk()
            ->assertJsonPath('data.sheba', 'IR062960000000100324200001')
            ->assertJsonPath('data.national_id', '0499370899')
            ->assertJsonPath('data.card_number', null);

        $this->putJson(route('v1.developer.payout-account.update'), $this->details(['bank_name' => 'بانک ملت']))->assertOk();

        $this->assertSame(1, PayoutAccount::count());
        $this->getJson(route('v1.developer.payout-account.show'))->assertJsonPath('data.bank_name', 'بانک ملت');
    }

    public function test_sheba_and_national_id_are_encrypted_at_rest(): void
    {
        $this->putJson(route('v1.developer.payout-account.update'), $this->details())->assertOk();

        $row = DB::table('payout_accounts')->first();
        $this->assertStringNotContainsString('0100324200001', $row->sheba);
        $this->assertStringNotContainsString('0499370899', $row->national_id);
    }

    public function test_invalid_details_are_rejected(): void
    {
        $this->putJson(route('v1.developer.payout-account.update'), $this->details([
            'sheba' => 'IR062960000000100324200002',
            'national_id' => '1234567890',
            'card_number' => '1234',
            'postal_code' => '123',
        ]))->assertJsonValidationErrors(['sheba', 'national_id', 'card_number', 'postal_code']);
    }

    public function test_organizations_give_their_eleven_digit_national_id(): void
    {
        $organization = User::factory()->organization()->create()->assignRole(Role::Developer->value);
        Sanctum::actingAs($organization);

        $this->putJson(route('v1.developer.payout-account.update'), $this->details())->assertJsonValidationErrors('national_id');
        $this->putJson(route('v1.developer.payout-account.update'), $this->details(['national_id' => '10101234567']))->assertOk();
    }

    public function test_players_cannot_save_payout_details(): void
    {
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::Player->value));

        $this->putJson(route('v1.developer.payout-account.update'), $this->details())->assertForbidden();
    }

    public function test_paid_listing_needs_payout_details_before_review(): void
    {
        $listing = Listing::factory()->for($this->developer, 'developer')->create([
            'type' => 'webxr',
            'external_url' => 'https://example.com/xr',
            'price' => 50000,
        ]);

        $this->postJson(route('v1.developer.apps.submit', $listing))->assertJsonValidationErrors('payout_account');

        PayoutAccount::factory()->for($this->developer)->create();

        $this->postJson(route('v1.developer.apps.submit', $listing))->assertOk()->assertJsonPath('data.status', 'in_review');
    }

    public function test_free_listing_needs_no_payout_details(): void
    {
        $listing = Listing::factory()->for($this->developer, 'developer')->create([
            'type' => 'webxr',
            'external_url' => 'https://example.com/xr',
            'price' => 0,
        ]);

        $this->postJson(route('v1.developer.apps.submit', $listing))->assertOk();
    }
}
