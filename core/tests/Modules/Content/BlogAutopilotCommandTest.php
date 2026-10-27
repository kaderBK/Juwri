<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Jobs\GenerateBlogPostJob;
use Juwri\Modules\Content\Jobs\TranslateBlogPostJob;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogTopic;
use Juwri\Modules\Content\Services\Blog\Autopilot\WriterContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Command-test writer fake: scripted suggestTopics, recorded calls, no AI.
 * writeDraft never runs here — GenerateBlogPostJob is Queue::faked.
 */
class CommandFakeWriter implements WriterContract
{
    public array $ideas = [];

    /** @var array<int, array{0:int,1:?int}> */
    public array $suggestCalls = [];

    public function suggestTopics(int $count, ?int $resellerId = null): array
    {
        $this->suggestCalls[] = [$count, $resellerId];

        return array_slice($this->ideas, 0, $count);
    }

    public function writeDraft(BlogTopic $topic): int
    {
        throw new \RuntimeException('writeDraft must not run in command tests.');
    }
}

/**
 * Blog Autopilot Lane A — A7 command + schedule behavior (sqlite, fake writer,
 * Queue::fake, zero AI/network).
 */
class BlogAutopilotCommandTest extends TestCase
{
    use RefreshDatabase;

    private CommandFakeWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new CommandFakeWriter();
        $this->app->instance(WriterContract::class, $this->writer);
        // The command no-ops unless the blog module is on; on for these tests.
        config()->set('settings.blog_enable', 1);
    }

    protected function tearDown(): void
    {
        foreach (['blog_autopilot_enabled', 'blog_autopilot_auto', 'blog_autopilot_weekly_cap', 'blog_autopilot_locales', 'blog_enable'] as $k) {
            config()->offsetUnset('settings.'.$k);
        }
        parent::tearDown();
    }

    private function queuedTopic(array $overrides = []): BlogTopic
    {
        return BlogTopic::create(array_merge([
            'title' => 'T'.\Illuminate\Support\Str::random(6),
            'keyword' => 'k', 'angle' => 'a', 'locale' => 'en',
            'status' => 'queued', 'source' => 'manual',
        ], $overrides));
    }

    private function draftedTopic(): BlogTopic
    {
        $blog = Blog::create(['title' => 'Draft '.\Illuminate\Support\Str::random(6), 'status' => 'pending', 'locale' => 'en']);

        return $this->queuedTopic(['status' => 'drafted', 'blog_id' => $blog->id]);
    }

    public function test_disabled_master_switch_is_a_full_no_op(): void
    {
        Queue::fake();
        $this->queuedTopic();

        $this->artisan('ai:blog-autopilot')
            ->expectsOutputToContain('disabled')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertSame([], $this->writer->suggestCalls);
    }

    public function test_draft_mode_dispatches_up_to_the_batch_for_platform_topics_and_never_publishes(): void
    {
        Queue::fake();
        config()->set('settings.blog_autopilot_enabled', '1');

        $first = $this->queuedTopic();
        $second = $this->queuedTopic();
        $third = $this->queuedTopic();
        $resellerTopic = $this->queuedTopic(['reseller_id' => 77]);
        $drafted = $this->draftedTopic();

        $this->artisan('ai:blog-autopilot')->assertExitCode(0);

        Queue::assertPushed(GenerateBlogPostJob::class, 2); // draft_batch default 2
        Queue::assertPushed(GenerateBlogPostJob::class, fn ($j) => $j->topicId === $first->id);
        Queue::assertPushed(GenerateBlogPostJob::class, fn ($j) => $j->topicId === $second->id);
        Queue::assertNotPushed(GenerateBlogPostJob::class, fn ($j) => $j->topicId === $resellerTopic->id);
        Queue::assertNotPushed(GenerateBlogPostJob::class, fn ($j) => $j->topicId === $third->id);

        // Draft mode NEVER publishes and never asks for topic ideas.
        $this->assertSame('drafted', $drafted->fresh()->status);
        $this->assertSame(0, Blog::where('status', 'publish')->count());
        $this->assertSame([], $this->writer->suggestCalls);
    }

    public function test_auto_mode_tops_the_queue_up_with_ai_topics_and_dispatches_them(): void
    {
        Queue::fake();
        config()->set('settings.blog_autopilot_enabled', '1');
        config()->set('settings.blog_autopilot_auto', '1');

        $this->queuedTopic(); // 1 of draft_batch 2 -> 1 missing
        $this->writer->ideas = [
            ['title' => 'Fresh AI idea', 'keyword' => 'fresh', 'angle' => 'new'],
        ];

        $this->artisan('ai:blog-autopilot')->assertExitCode(0);

        $this->assertSame([[1, null]], $this->writer->suggestCalls, 'asked for exactly the missing count, platform-scoped');
        $ai = BlogTopic::where('source', 'ai')->get();
        $this->assertCount(1, $ai);
        $this->assertSame('queued', $ai[0]->status);
        $this->assertSame('Fresh AI idea', $ai[0]->title);
        $this->assertNull($ai[0]->reseller_id);

        Queue::assertPushed(GenerateBlogPostJob::class, 2); // manual + topped-up
    }

    public function test_auto_mode_publishes_oldest_drafted_up_to_the_rolling_cap_and_fans_out_translations(): void
    {
        Queue::fake();
        config()->set('settings.blog_autopilot_enabled', '1');
        config()->set('settings.blog_autopilot_auto', '1');
        config()->set('settings.blog_autopilot_locales', 'ar');
        $this->writer->ideas = []; // top-up returns nothing — isolate publishing

        // A published topic OUTSIDE the 7-day window must not consume the cap.
        $old = $this->queuedTopic(['status' => 'published']);
        \Illuminate\Support\Facades\DB::table((new BlogTopic)->getTable())
            ->where('id', $old->id)->update(['updated_at' => now()->subDays(8)]);

        $topics = collect(range(1, 5))->map(fn () => $this->draftedTopic());

        $this->artisan('ai:blog-autopilot')->assertExitCode(0);

        // Cap default 3: the three OLDEST drafted topics published.
        $this->assertSame(3, BlogTopic::where('status', 'published')->where('id', '!=', $old->id)->count());
        $this->assertSame('published', $topics[0]->fresh()->status);
        $this->assertSame('published', $topics[2]->fresh()->status);
        $this->assertSame('drafted', $topics[3]->fresh()->status);
        $this->assertSame(3, Blog::where('status', 'publish')->count());
        Queue::assertPushed(TranslateBlogPostJob::class, 3);

        // Same window, second run: cap already used — nothing more.
        $this->artisan('ai:blog-autopilot')->assertExitCode(0);
        $this->assertSame(3, Blog::where('status', 'publish')->count());

        // Raising the cap option to 4 frees exactly one more slot.
        config()->set('settings.blog_autopilot_weekly_cap', '4');
        $this->artisan('ai:blog-autopilot')->assertExitCode(0);
        $this->assertSame(4, Blog::where('status', 'publish')->count());
        $this->assertSame('published', $topics[3]->fresh()->status);
        $this->assertSame('drafted', $topics[4]->fresh()->status);
    }

    public function test_auto_publish_skips_and_fails_a_drafted_topic_whose_blog_was_deleted(): void
    {
        Queue::fake();
        config()->set('settings.blog_autopilot_enabled', '1');
        config()->set('settings.blog_autopilot_auto', '1');
        $this->writer->ideas = [];

        $orphan = $this->queuedTopic(['status' => 'drafted']); // no blog_id
        $healthy = $this->draftedTopic();

        $this->artisan('ai:blog-autopilot')->assertExitCode(0);

        $this->assertSame('failed', $orphan->fresh()->status);
        $this->assertStringContainsString('missing', (string) $orphan->fresh()->error);
        $this->assertSame('published', $healthy->fresh()->status);
    }

    public function test_the_command_is_scheduled_hourly_without_overlapping(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $event = collect($schedule->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'ai:blog-autopilot'));

        $this->assertNotNull($event, 'ai:blog-autopilot must be scheduled');
        $this->assertSame('25 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'must not stack slow LLM ticks');
    }

    public function test_disabled_blog_module_is_a_no_op_even_when_enabled(): void
    {
        Queue::fake();
        config()->set('settings.blog_autopilot_enabled', '1');
        config()->set('settings.blog_enable', 0); // module off
        $this->queuedTopic();

        $this->artisan('ai:blog-autopilot')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_backlog_cap_stops_drafting_when_unpublished_posts_pile_up(): void
    {
        Queue::fake();
        config()->set('settings.blog_autopilot_enabled', '1');
        config()->set('blog_autopilot.max_drafted_backlog', 3);

        // 3 drafted (unpublished) platform posts = backlog at capacity.
        $this->draftedTopic();
        $this->draftedTopic();
        $this->draftedTopic();
        // A fresh queued topic that would otherwise be drafted this tick.
        $this->queuedTopic();

        $this->artisan('ai:blog-autopilot')
            ->expectsOutputToContain('Backlog full')
            ->assertExitCode(0);

        Queue::assertNothingPushed(); // drafting skipped while the backlog is full
    }

    public function test_backlog_cap_counts_queued_and_drafting_toward_the_ceiling(): void
    {
        Queue::fake();
        config()->set('settings.blog_autopilot_enabled', '1');
        config()->set('blog_autopilot.max_drafted_backlog', 2);

        $this->queuedTopic();
        $this->queuedTopic(['status' => 'drafting']);

        $this->artisan('ai:blog-autopilot')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_reaper_returns_stale_drafting_topics_to_failed(): void
    {
        Queue::fake();
        config()->set('settings.blog_autopilot_enabled', '1');
        config()->set('blog_autopilot.stuck_after_minutes', 20);

        $stuck = $this->queuedTopic(['status' => 'drafting']);
        // Backdate updated_at past the stuck window without touching it via save().
        BlogTopic::whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(30)]);

        $fresh = $this->queuedTopic(['status' => 'drafting']); // recent — not reaped

        $this->artisan('ai:blog-autopilot')->assertExitCode(0);

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertStringContainsString('Reaped', (string) $stuck->fresh()->error);
        $this->assertSame('drafting', $fresh->fresh()->status);
    }

    public function test_reaper_runs_even_when_the_master_switch_is_off(): void
    {
        Queue::fake();
        // enabled NOT set → command no-ops for drafting, but still reaps.
        config()->set('blog_autopilot.stuck_after_minutes', 20);
        $stuck = $this->queuedTopic(['status' => 'drafting']);
        BlogTopic::whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(30)]);

        $this->artisan('ai:blog-autopilot')->assertExitCode(0);

        $this->assertSame('failed', $stuck->fresh()->status);
    }
}
