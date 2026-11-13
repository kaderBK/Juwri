<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\OutputEncoding;

use App\Models\MenuBanner;
use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Storefront\Drops\StoryDrop;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-73: story buttons and banners end up in a public href, so javascript:/data: must never be stored. */
class StoryAndBannerLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware();
    }

    /** @return array{0: User, 1: Store} */
    private function ownerWithStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    /** @return array<string, array{0: string}> */
    public static function badLinks(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript with tab' => ["java\tscript:alert(1)"],
            'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
        ];
    }

    #[DataProvider('badLinks')]
    public function test_story_rejects_a_dangerous_cta_url(string $url): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $this->actingAs($owner)->postJson(route('stories.store'), [
            'store_id' => $store->id, 'media_url' => 's.webp', 'cta_label' => '"><img src=x onerror=alert(1)>',
            'cta_url' => $url, 'active' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('cta_url');

        $this->assertSame(0, Story::where('store_id', $store->id)->count());
    }

    public function test_story_accepts_https_and_site_relative_links(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        foreach (['https://example.com/menu', '/shop?item=1'] as $url) {
            $this->actingAs($owner)->post(route('stories.store'), [
                'store_id' => $store->id, 'media_url' => 's.webp', 'cta_url' => $url, 'active' => 1,
            ]);
        }

        $this->assertEqualsCanonicalizing(
            ['https://example.com/menu', '/shop?item=1'],
            Story::where('store_id', $store->id)->pluck('cta_url')->all()
        );
    }

    public function test_the_story_drop_never_hands_a_dangerous_link_to_the_theme(): void
    {
        $drop = new StoryDrop(['cta_url' => 'javascript:alert(1)', 'caption' => 'x']);
        $this->assertNull($drop->cta_url());

        $drop = new StoryDrop(['cta_url' => 'https://example.com/a']);
        $this->assertSame('https://example.com/a', $drop->cta_url());
    }

    #[DataProvider('badLinks')]
    public function test_banner_rejects_a_dangerous_link_url(string $url): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $this->actingAs($owner)->postJson(route('banners.store'), [
            'store_id' => $store->id, 'image' => 'b.webp', 'title' => '<script>alert(1)</script>', 'link_url' => $url,
        ])->assertStatus(422)->assertJsonValidationErrors('link_url');

        $this->assertSame(0, MenuBanner::where('store_id', $store->id)->count());
    }
}
