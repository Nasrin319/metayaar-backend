<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\StaffRole;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Banner;
use App\Support\ArticleHtml;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('public');
    }

    private function actingAsStaff(StaffRole $role = StaffRole::Admin): void
    {
        Sanctum::actingAs(Admin::factory()->create()->assignRole($role->value));
    }

    /**
     * @return array<string, mixed>
     */
    private function bannerData(array $overrides = []): array
    {
        return [
            'title' => 'نوروز',
            'image' => UploadedFile::fake()->image('b.png', 1500, 500),
            'position' => 0,
            'is_active' => '1',
            ...$overrides,
        ];
    }

    public function test_staff_without_the_permission_cannot_manage_content(): void
    {
        $this->actingAsStaff(StaffRole::Moderator);

        $this->getJson(route('v1.admin.articles.index'))->assertForbidden();
        $this->getJson(route('v1.admin.banners.index'))->assertForbidden();
    }

    public function test_curator_manages_content(): void
    {
        $this->actingAsStaff(StaffRole::Curator);

        $this->getJson(route('v1.admin.articles.index'))->assertOk();
    }

    public function test_article_lifecycle_with_persian_slug_and_image(): void
    {
        $this->actingAsStaff();

        $id = $this->post(route('v1.admin.articles.store'), [
            'title' => 'واقعیت مجازی چیست؟',
            'body' => 'متن مقاله',
            'is_published' => '0',
            'image' => UploadedFile::fake()->image('c.png', 1200, 675),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'واقعیت-مجازی-چیست')
            ->assertJsonPath('data.published_at', null)
            ->json('data.id');

        $article = Article::find($id);
        Storage::disk('public')->assertExists($article->image_path);
        $firstImage = $article->image_path;

        // drafts are hidden from visitors
        $this->getJson('/api/v1/articles')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/articles/'.rawurlencode($article->slug))->assertNotFound();

        // the admin panel updates by POST with _method, since PHP only parses multipart bodies on POST
        $this->post(route('v1.admin.articles.update', $id), [
            '_method' => 'PUT',
            'title' => 'واقعیت مجازی چیست؟',
            'slug' => $article->slug,
            'body' => 'متن تازه',
            'is_published' => '1',
            'image' => UploadedFile::fake()->image('d.png', 1200, 675),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.slug', $article->slug);

        Storage::disk('public')->assertMissing($firstImage);
        $article->refresh();

        $this->getJson('/api/v1/articles')->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.body');
        $this->getJson('/api/v1/articles/'.rawurlencode($article->slug))->assertOk()->assertJsonPath('data.body', 'متن تازه');

        $this->deleteJson(route('v1.admin.articles.destroy', $id))->assertNoContent();
        Storage::disk('public')->assertMissing($article->image_path);
    }

    public function test_body_keeps_formatting_but_loses_anything_that_could_run(): void
    {
        $this->actingAsStaff();

        $body = $this->postJson(route('v1.admin.articles.store'), [
            'title' => 'متن',
            'is_published' => true,
            'body' => '<h2 onclick="x()">تیتر</h2><p style="color:red">متن <strong>پررنگ</strong><script>alert(1)</script></p>'
                .'<a href="javascript:alert(1)">بد</a><a href="https://example.com">خوب</a>'
                .'<figure class="image"><img src="/storage/articles/body/a.png" alt="عکس" onerror="x()"></figure><iframe src="https://evil.example"></iframe>',
        ])->assertCreated()->json('data.body');

        $this->assertStringContainsString('<h2>تیتر</h2>', $body);
        $this->assertStringContainsString('<strong>پررنگ</strong>', $body);
        $this->assertStringContainsString('href="https://example.com"', $body);
        $this->assertStringContainsString('rel="noopener noreferrer"', $body);
        $this->assertStringContainsString('src="/storage/articles/body/a.png"', $body);
        foreach (['script', 'onclick', 'onerror', 'style=', 'javascript:', 'iframe'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $body);
        }
    }

    public function test_a_body_with_nothing_to_read_or_too_long_is_refused(): void
    {
        $this->actingAsStaff();

        foreach (['', '<p>&nbsp;</p>', '<script>alert(1)</script>', '<p>'.str_repeat('x', 200001).'</p>'] as $body) {
            $errors = $this->postJson(route('v1.admin.articles.store'), ['title' => 'متن', 'is_published' => true, 'body' => $body])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('body')
                ->json('errors.body.0');
            $this->assertStringContainsString('متن مقاله', $errors);
        }
    }

    public function test_pictures_in_the_text_are_uploaded_and_removed_with_the_article(): void
    {
        $this->actingAsStaff();

        $url = $this->post(route('v1.admin.article-images.store'), ['upload' => UploadedFile::fake()->image('p.png', 800, 400)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('url');
        $path = substr($url, strpos($url, 'articles/body/'));
        Storage::disk('public')->assertExists($path);

        $this->post(route('v1.admin.article-images.store'), ['upload' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $id = $this->postJson(route('v1.admin.articles.store'), [
            'title' => 'عکس', 'is_published' => true, 'body' => "<figure class=\"image\"><img src=\"/storage/{$path}\" alt=\"\"></figure>",
        ])->assertCreated()->json('data.id');

        $this->deleteJson(route('v1.admin.articles.destroy', $id))->assertNoContent();
        Storage::disk('public')->assertMissing($path);
    }

    public function test_old_plain_text_becomes_safe_html(): void
    {
        $this->assertSame(
            '<p>اول &lt;b&gt;<br>خط دوم</p><h2>تیتر</h2><ul><li>یک</li><li>دو</li></ul>',
            ArticleHtml::fromPlainText("اول <b>\nخط دوم\n\n## تیتر\n\n- یک\n- دو"),
        );
    }

    public function test_same_title_gets_its_own_slug_and_html_in_the_title_is_refused(): void
    {
        $this->actingAsStaff();
        Article::factory()->create(['title' => 'هدست']);

        $data = ['title' => 'هدست', 'body' => 'متن', 'is_published' => true];
        $slug = $this->postJson(route('v1.admin.articles.store'), $data)->assertCreated()->json('data.slug');
        $this->assertNotSame('هدست', $slug);

        $this->postJson(route('v1.admin.articles.store'), ['title' => '<script>alert(1)</script>'] + $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_only_live_banners_are_public(): void
    {
        Banner::factory()->create(['title' => 'always']);
        Banner::factory()->create(['title' => 'current', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        Banner::factory()->create(['title' => 'future', 'starts_at' => now()->addDay()]);
        Banner::factory()->create(['title' => 'ended', 'ends_at' => now()->subMinute()]);
        Banner::factory()->create(['title' => 'off', 'is_active' => false]);

        $titles = collect($this->getJson('/api/v1/banners')->assertOk()->json('data'))->pluck('title')->sort()->values()->all();

        $this->assertSame(['always', 'current'], $titles);
    }

    public function test_banner_dates_keep_their_moment_and_end_must_follow_start(): void
    {
        $this->actingAsStaff();

        $banner = $this->post(route('v1.admin.banners.store'), $this->bannerData([
            'starts_at' => '2027-03-20T00:00:00+03:30',
            'ends_at' => '2027-04-02T23:59:00+03:30',
            'link_url' => '/apps',
        ]), ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('2027-03-19T20:30:00+00:00', Banner::find($banner['id'])->starts_at->toIso8601String());

        $this->post(route('v1.admin.banners.store'), $this->bannerData([
            'starts_at' => '2027-04-02T00:00:00+03:30',
            'ends_at' => '2027-03-20T00:00:00+03:30',
        ]), ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('ends_at');

        // an end date alone is fine
        $this->post(route('v1.admin.banners.store'), $this->bannerData(['ends_at' => '2027-03-20T00:00:00+03:30']), ['Accept' => 'application/json'])
            ->assertCreated();
    }

    public function test_banner_needs_an_image_and_a_safe_link(): void
    {
        $this->actingAsStaff();

        $this->postJson(route('v1.admin.banners.store'), ['title' => 'x', 'position' => 0, 'is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        foreach (['javascript:alert(1)', '//evil.example', 'evil.example'] as $link) {
            $this->post(route('v1.admin.banners.store'), $this->bannerData(['link_url' => $link]), ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('link_url');
        }
    }

    public function test_banner_update_keeps_the_image_unless_replaced_and_delete_removes_it(): void
    {
        $this->actingAsStaff();

        $id = $this->post(route('v1.admin.banners.store'), $this->bannerData(), ['Accept' => 'application/json'])->json('data.id');
        $path = Banner::find($id)->image_path;

        $this->post(route('v1.admin.banners.update', $id), ['_method' => 'PUT', 'title' => 'یلدا', 'position' => 1, 'is_active' => '0'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.is_live', false);

        $this->assertSame($path, Banner::find($id)->image_path);

        $this->deleteJson(route('v1.admin.banners.destroy', $id))->assertNoContent();
        Storage::disk('public')->assertMissing($path);
    }
}
