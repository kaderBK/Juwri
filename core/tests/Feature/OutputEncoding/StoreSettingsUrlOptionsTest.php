<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\OutputEncoding;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Store\StoreSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** P6-73: the Google review link is echoed into a public href and a redirect, so only http(s) is stored. */
class StoreSettingsUrlOptionsTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function dangerous(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'mixed case with tab' => ["JaVa\tScRiPt:alert(1)"],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'img payload' => ['"><img src=x onerror=alert(1)>'],
        ];
    }

    #[DataProvider('dangerous')]
    public function test_google_review_url_drops_anything_that_is_not_http(string $value): void
    {
        $store = $this->store();

        app(StoreSettingsService::class)->applyOptions($store, ['google_review_url' => $value]);

        $this->assertSame('', (string) StoreOption::getStoreOption($store->id, 'google_review_url'));
    }

    public function test_google_review_url_keeps_a_real_link(): void
    {
        $store = $this->store();

        app(StoreSettingsService::class)->applyOptions($store, ['google_review_url' => 'https://g.page/r/abc/review']);

        $this->assertSame('https://g.page/r/abc/review', StoreOption::getStoreOption($store->id, 'google_review_url'));
    }

    public function test_social_urls_cannot_become_javascript_links(): void
    {
        $store = $this->store();

        app(StoreSettingsService::class)->applyOptions($store, [
            'instagram_url' => 'javascript:alert(1)',
            'facebook_url' => 'javascript://x.test/%0aalert(1)',
        ]);

        $this->assertSame('#', StoreOption::getStoreOption($store->id, 'instagram_url'));
        $this->assertSame('#', StoreOption::getStoreOption($store->id, 'facebook_url'));
    }
}
