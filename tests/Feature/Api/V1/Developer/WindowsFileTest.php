<?php

namespace Tests\Feature\Api\V1\Developer;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Enums\Role;
use App\Models\Listing;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class WindowsFileTest extends TestCase
{
    use RefreshDatabase;

    private User $developer;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('apks');
        Storage::fake('public');
        $this->seed(RoleSeeder::class);
        $this->developer = User::factory()->create()->assignRole(Role::Developer->value);
        $this->listing = Listing::factory()->for($this->developer, 'developer')->create(['type' => ListingType::Windows, 'external_url' => null]);
        Sanctum::actingAs($this->developer);
    }

    /**
     * A minimal valid 64-bit Windows program header.
     */
    private function exe(int $characteristics = 0x0022, bool $signed = false): string
    {
        $pe = 0x80;
        $bytes = 'MZ'.str_repeat("\0", $pe - 2);
        $bytes = substr_replace($bytes, pack('V', $pe), 0x3C, 4);
        $header = "PE\0\0".pack('v', 0x8664).str_repeat("\0", 16).pack('v', $characteristics);
        $optional = pack('v', 0x20B).str_repeat("\0", 110).str_repeat("\0", 36).pack('V', $signed ? 2048 : 0).str_repeat("\0", 200);

        return $bytes.$header.$optional;
    }

    /**
     * @param  array<string, string>  $files
     */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $contents = file_get_contents($path);
        unlink($path);

        return $contents;
    }

    private function upload(string $name, string $contents): TestResponse
    {
        $id = $this->postJson(route('v1.developer.apps.apk-uploads.store', $this->listing), ['filename' => $name, 'size' => strlen($contents)])
            ->assertCreated()
            ->json('data.id');

        $this->post(route('v1.developer.apps.apk-uploads.chunk', [$this->listing, $id]), [
            'offset' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('chunk', $contents),
        ], ['Accept' => 'application/json'])->assertOk();

        return $this->postJson(route('v1.developer.apps.apk-uploads.complete', [$this->listing, $id]));
    }

    public function test_developer_uploads_an_exe_and_the_listing_stays_a_windows_listing(): void
    {
        $this->upload('Game Setup.EXE', $this->exe())
            ->assertOk()
            ->assertJsonPath('data.type', 'windows')
            ->assertJsonPath('data.file_report.kind', 'exe')
            ->assertJsonPath('data.file_report.findings.0.level', 'warning');

        $listing = $this->listing->fresh();
        $this->assertStringEndsWith('.exe', $listing->apk_path);
        Storage::disk('apks')->assertExists($listing->apk_path);

        $listing->update(['status' => ListingStatus::Published, 'price' => 0]);
        $this->get(route('v1.apps.download', $listing))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.microsoft.portable-executable')
            ->assertDownload("{$listing->slug}.exe");
    }

    public function test_a_signed_exe_is_reported_as_signed(): void
    {
        $this->upload('game.exe', $this->exe(signed: true))
            ->assertOk()
            ->assertJsonPath('data.file_report.findings.0.level', 'ok');
    }

    public function test_developer_uploads_a_zip_with_a_program_inside(): void
    {
        $this->upload('game.zip', $this->zip(['game/Game.exe' => 'x', 'game/run.bat' => 'y']))
            ->assertOk()
            ->assertJsonPath('data.file_report.kind', 'zip')
            ->assertJsonPath('data.file_report.findings.1.level', 'warning');

        $this->assertStringEndsWith('.zip', $this->listing->fresh()->apk_path);
    }

    public function test_a_complete_unity_build_is_accepted_and_debug_folders_get_a_warning(): void
    {
        $this->upload('game.zip', $this->zip([
            'Build/MyGame.exe' => 'x',
            'Build/UnityPlayer.dll' => 'x',
            'Build/MyGame_Data/globalgamemanagers' => 'x',
            'Build/MyGame_BurstDebugInformation_DoNotShip/a.txt' => 'x',
        ]))
            ->assertOk()
            ->assertJsonPath('data.file_report.findings.0.level', 'ok')
            ->assertJsonPath('data.file_report.findings.1.level', 'warning')
            ->assertJsonPath('data.file_report.facts.0.value', 'خروجی Unity در ZIP');
    }

    public function test_incomplete_unity_builds_are_refused(): void
    {
        $this->upload('game.zip', $this->zip(['MyGame.exe' => 'x', 'UnityPlayer.dll' => 'x']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('chunk');

        $this->upload('MyGame.exe', $this->exe().'UnityPlayer.dll')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('chunk');
    }

    public function test_files_that_cannot_work_are_refused_and_removed(): void
    {
        $bad = [
            ['fake.exe', 'not a program at all, just text, long enough to read a header from it............'],
            ['library.exe', $this->exe(0x2022)],
            ['empty.zip', $this->zip(['readme.txt' => 'x'])],
            ['escape.zip', $this->zip(['../evil.exe' => 'x'])],
            ['broken.zip', 'not a zip'],
        ];

        foreach ($bad as [$name, $contents]) {
            $this->upload($name, $contents)->assertUnprocessable()->assertJsonValidationErrors('chunk');
            $this->assertNull($this->listing->fresh()->apk_path, $name);
        }

        $this->assertSame([], Storage::disk('apks')->allFiles('listings'));
    }

    public function test_windows_listing_only_takes_exe_or_zip_and_others_only_take_apk(): void
    {
        $this->postJson(route('v1.developer.apps.apk-uploads.store', $this->listing), ['filename' => 'game.apk', 'size' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filename');

        $apkListing = Listing::factory()->for($this->developer, 'developer')->create(['type' => ListingType::Apk]);
        $this->postJson(route('v1.developer.apps.apk-uploads.store', $apkListing), ['filename' => 'game.exe', 'size' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('filename');
    }

    public function test_a_listing_with_a_file_cannot_switch_between_windows_and_headset(): void
    {
        $this->upload('game.exe', $this->exe())->assertOk();

        $this->putJson(route('v1.developer.apps.update', $this->listing), ['title' => 'Game', 'type' => 'apk'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->putJson(route('v1.developer.apps.update', $this->listing), ['title' => 'Game', 'type' => 'windows'])->assertOk();
    }

    public function test_an_empty_listing_can_switch_and_needs_a_file_before_review(): void
    {
        $this->putJson(route('v1.developer.apps.update', $this->listing), ['title' => 'Game', 'type' => 'apk'])->assertOk();
        $this->putJson(route('v1.developer.apps.update', $this->listing), ['title' => 'Game', 'type' => 'windows'])->assertOk();

        $this->postJson(route('v1.developer.apps.submit', $this->listing))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('apk');
    }
}
