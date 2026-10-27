<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\BlogTopic;
use Juwri\Modules\Content\Database\Seeders\SeoAutopilotTopicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO Growth W5.1 — Blog Autopilot topic-queue seed.
 *
 * The seeder only fills the backlog (queued, platform, manual) from
 * KEYWORDS.md §Longtails; it never drafts/publishes. Asserts the full count,
 * per-locale counts, verbatim keyword fidelity (incl. non-Latin scripts), the
 * "(2026)" title pattern, and idempotency on re-run.
 */
class SeoAutopilotTopicSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Expected topic count per locale (KEYWORDS §Longtails). */
    private const EXPECTED = [
        'en' => 6, 'ar' => 5, 'tr' => 5, 'de' => 4, 'fr' => 3,
        'es' => 4, 'pt' => 4, 'ru' => 3, 'zh' => 2, 'hi' => 2, 'ur' => 2,
    ];

    public function test_seeds_all_longtails_as_queued_platform_topics(): void
    {
        $this->seed(SeoAutopilotTopicSeeder::class);

        $total = array_sum(self::EXPECTED);
        $this->assertSame($total, BlogTopic::count());
        $this->assertSame(40, $total);

        // Every seeded row: queued + platform (reseller_id NULL) + manual source.
        $this->assertSame(
            $total,
            BlogTopic::whereNull('reseller_id')->where('status', 'queued')->where('source', 'manual')->count()
        );
        // Nothing was drafted or published by the seeder.
        $this->assertSame(0, BlogTopic::whereIn('status', ['drafting', 'drafted', 'published'])->count());

        foreach (self::EXPECTED as $locale => $count) {
            $this->assertSame($count, BlogTopic::where('locale', $locale)->count(), "locale {$locale} count");
        }
    }

    public function test_keyword_fidelity_and_title_pattern(): void
    {
        $this->seed(SeoAutopilotTopicSeeder::class);

        // Latin keyword: verbatim keyword + ucfirst "(2026)" title.
        $en = BlogTopic::where('keyword', 'best free qr menu app')->first();
        $this->assertNotNull($en);
        $this->assertSame('Best free qr menu app (2026)', $en->title);

        // Non-Latin keywords preserved byte-for-byte (verbatim from KEYWORDS).
        $this->assertTrue(BlogTopic::where('keyword', 'كيف اسوي منيو الكتروني')->exists());
        $this->assertTrue(BlogTopic::where('keyword', '扫码点餐系统多少钱')->exists());
        $this->assertTrue(BlogTopic::where('keyword', 'yemeksepeti komisyon oranı')->exists());

        // Every title ends with the "(2026)" freshness marker.
        foreach (BlogTopic::all() as $t) {
            $this->assertStringEndsWith(' (2026)', $t->title, "title: {$t->title}");
        }

        // hi/ur carry the English-body/native-H2 routing note.
        $hi = BlogTopic::where('locale', 'hi')->first();
        $this->assertIsArray($hi->meta);
        $this->assertArrayHasKey('note', $hi->meta);
    }

    public function test_reseeding_is_idempotent(): void
    {
        $this->seed(SeoAutopilotTopicSeeder::class);
        $first = BlogTopic::count();

        $this->seed(SeoAutopilotTopicSeeder::class);

        $this->assertSame($first, BlogTopic::count(), 'Re-running the seeder must not duplicate topics');
    }
}
