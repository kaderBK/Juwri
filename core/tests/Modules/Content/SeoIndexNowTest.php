<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Jobs\PingIndexNow;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SeoIndexNowTest extends TestCase
{
    use RefreshDatabase;

    private ?string $key = null;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        // The key-file route is registered at app boot from the config default,
        // so use that value rather than overriding it (an override wouldn't
        // reach the already-registered route).
        $this->key = config('seo.indexnow_key');
        config(['settings.include_language_code' => 0, 'seo.indexnow_test_optin' => true]);
        $this->withoutMiddleware(\App\Http\Middleware\Installed::class);
    }

    private function makeBlog(array $attrs = []): Blog
    {
        $author = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Blog::create(array_merge([
            'author' => $author->id, 'title' => 'A published post', 'slug' => 'p-'.uniqid(),
            'description' => '<p>b</p>', 'image' => 'x.png', 'status' => 'publish',
        ], $attrs));
    }

    public function test_key_file_route_serves_the_key(): void
    {
        $response = $this->get('/'.$this->key.'.txt');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame($this->key, $response->getContent());
    }

    public function test_publishing_a_post_queues_indexnow_ping(): void
    {
        Queue::fake();

        $blog = $this->makeBlog();

        Queue::assertPushed(PingIndexNow::class, function ($job) use ($blog) {
            return collect($job->urls)->contains(fn ($u) => str_contains($u, $blog->slug));
        });
    }

    public function test_draft_does_not_queue_ping(): void
    {
        Queue::fake();

        $this->makeBlog(['status' => 'pending']);

        Queue::assertNotPushed(PingIndexNow::class);
    }

    public function test_no_key_configured_disables_ping(): void
    {
        config(['seo.indexnow_key' => null]);
        Queue::fake();

        $this->makeBlog();

        Queue::assertNotPushed(PingIndexNow::class);
    }

    public function test_job_posts_to_bing_and_yandex_endpoints(): void
    {
        Http::fake();
        config(['seo.indexnow_endpoints' => ['https://api.indexnow.org/indexnow', 'https://yandex.com/indexnow']]);

        new PingIndexNow(['https://juwri.com/blog/5/x'])->handle();

        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.indexnow.org')
            && $req['host'] === 'juwri.com'
            && $req['key'] === $this->key
            && in_array('https://juwri.com/blog/5/x', $req['urlList']));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'yandex.com'));
    }
}
