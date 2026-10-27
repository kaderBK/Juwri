<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Console\Commands;

use Juwri\Modules\Content\Jobs\GenerateBlogPostJob;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogTopic;
use Juwri\Modules\Content\Services\Blog\Autopilot\WriterContract;
use Illuminate\Console\Command;
use Juwri\Core\Settings\PlatformSettings;

/**
 * Blog Autopilot A7 — the scheduler tick (PLATFORM blog only; reseller
 * generation is on-demand via the portal, never scheduled).
 *
 * Draft mode (always, when enabled): dispatch GenerateBlogPostJob for up to
 * draft_batch queued platform topics — posts land as 'pending' for admin
 * review. Full-auto (blog_autopilot_auto='1') additionally tops the queue up
 * with AI-suggested topics and publishes the oldest drafted posts, hard-capped
 * per rolling 7 days (compliance guardrail — never bulk).
 */
class BlogAutopilotRun extends Command
{
    protected $signature = 'ai:blog-autopilot';

    protected $description = 'Blog Autopilot tick: draft queued platform topics; in full-auto also top up topics and publish within the weekly cap.';

    public function handle(WriterContract $writer): int
    {
        // Reaper runs every tick regardless of the master switch: return topics
        // stuck in 'drafting' (worker died mid-generation, skipping the job's
        // catch/failed hooks) to 'failed' so they are visible and retryable.
        // Covers reseller on-demand drafts too.
        $reaped = $this->reapStuck();

        if ($this->option_('blog_autopilot_enabled') !== '1' || ! config('settings.blog_enable')) {
            $this->info('Blog Autopilot is disabled (master switch off or blog module disabled).');
            $this->summary(['reaped' => $reaped]);

            return self::SUCCESS;
        }

        $batch = max(1, (int) config('blog_autopilot.draft_batch', 2));
        $auto = $this->option_('blog_autopilot_auto') === '1';

        // Cost guardrail: how many platform posts are queued/drafting/drafted but
        // not yet published or rejected? In full-auto the hourly draft rate far
        // outruns the weekly publish cap, so without a ceiling the unpublished
        // backlog — and the AI spend behind it — grows without bound. Applies to
        // draft mode too (posts awaiting manual approval).
        $backlogCap = max(0, (int) config('blog_autopilot.max_drafted_backlog', 10));
        $backlog = BlogTopic::platform()->whereIn('status', ['queued', 'drafting', 'drafted'])->count();
        $atCapacity = $backlogCap > 0 && $backlog >= $backlogCap;

        // (a) full-auto top-up BEFORE drafting, so one tick replenishes AND
        // drafts — the pipeline keeps moving without manual topic entry.
        $toppedUp = 0;
        if ($auto && ! $atCapacity) {
            $toppedUp = $this->topUp($writer, $batch);
        }

        // (b) Draft: oldest queued platform topics, up to the batch size — unless
        // the backlog is already full.
        $dispatched = 0;
        if ($atCapacity) {
            $this->info(sprintf('Backlog full (%d/%d unpublished) — skipping drafting.', $backlog, $backlogCap));
        } else {
            $queued = BlogTopic::queued()->platform()->orderBy('id')->limit($batch)->get();
            foreach ($queued as $topic) {
                GenerateBlogPostJob::dispatch($topic->id);
            }
            $dispatched = $queued->count();
        }
        $this->info(sprintf('Dispatched %d draft job(s).', $dispatched));

        // (c) full-auto publish within the rolling 7-day cap.
        $published = 0;
        if ($auto) {
            $published = $this->publishWithinCap();
        }

        $this->summary([
            'reaped' => $reaped,
            'topped_up' => $toppedUp,
            'dispatched' => $dispatched,
            'published' => $published,
            'backlog' => $backlog,
        ]);

        return self::SUCCESS;
    }

    /**
     * Return topics stuck in 'drafting' (job died without running its
     * catch/failed hooks) to 'failed'. Runs every tick, master switch or not.
     */
    protected function reapStuck(): int
    {
        $after = max(1, (int) config('blog_autopilot.stuck_after_minutes', 20));
        try {
            return (int) BlogTopic::where('status', 'drafting')
                ->where('updated_at', '<', now()->subMinutes($after))
                ->update([
                    'status' => 'failed',
                    'error' => 'Reaped: stuck in drafting (worker died or timed out).',
                ]);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }

    /**
     * Top the queued-platform-topic backlog up to the draft batch size.
     *
     * @return int topics created
     */
    protected function topUp(WriterContract $writer, int $batch): int
    {
        $queued = BlogTopic::queued()->platform()->count();
        $missing = $batch - $queued;
        if ($missing < 1) {
            return 0;
        }

        try {
            $ideas = $writer->suggestTopics($missing);
        } catch (\Throwable $e) {
            report($e);
            $this->warn('Topic top-up failed: '.$e->getMessage());

            return 0;
        }

        foreach ($ideas as $idea) {
            BlogTopic::create([
                'title' => $idea['title'],
                'keyword' => $idea['keyword'],
                'angle' => $idea['angle'],
                'locale' => (string) config('app.locale', 'en'),
                'status' => 'queued',
                'source' => 'ai',
            ]);
        }
        $this->info(sprintf('Topped up %d AI topic(s).', count($ideas)));

        return count($ideas);
    }

    /**
     * Publish the oldest drafted platform topics while the rolling 7-day
     * publish count stays under the cap. Publishing the Blog fires IndexNow +
     * the translation fan-out via the model hooks. Never bulk: hard-capped.
     */
    protected function publishWithinCap(): int
    {
        // Clamp: the weekly cap is a persisted, unvalidated option; keep it in a
        // sane band so a bad value can't disable the guardrail or bulk-publish.
        $cap = (int) ($this->option_('blog_autopilot_weekly_cap')
            ?? config('blog_autopilot.weekly_publish_cap', 3));
        $cap = max(0, min(50, $cap));
        if ($cap < 1) {
            $this->info('Weekly cap is 0 — nothing auto-published.');

            return 0;
        }

        // Published topics are terminal, so updated_at marks the publish time.
        $used = BlogTopic::platform()->where('status', 'published')
            ->where('updated_at', '>=', now()->subDays(7))
            ->count();

        $room = $cap - $used;
        if ($room < 1) {
            $this->info(sprintf('Weekly cap reached (%d/%d in the last 7 days).', $used, $cap));

            return 0;
        }

        $drafted = BlogTopic::platform()->where('status', 'drafted')
            ->orderBy('id')->limit($room)->get();

        $published = 0;
        foreach ($drafted as $topic) {
            $blog = $topic->blog_id ? Blog::find($topic->blog_id) : null;
            if (! $blog) {
                $topic->update(['status' => 'failed', 'error' => 'Drafted blog row is missing (deleted?).']);
                continue;
            }

            $blog->status = 'publish';
            $blog->save(); // fires IndexNow + TranslateBlogPostJob hooks
            $topic->update(['status' => 'published']);
            $published++;
        }

        $this->info(sprintf('Auto-published %d post(s) (%d/%d used this week).', $published, $used + $published, $cap));

        return $published;
    }

    /**
     * Structured per-tick summary so scheduled runs are observable.
     *
     * @param array<string,int> $counts
     */
    protected function summary(array $counts): void
    {
        \Illuminate\Support\Facades\Log::info('[BlogAutopilot] tick', $counts);
    }

    /** Option read: hydrated config('settings.*') first, then the Option table. */
    protected function option_(string $key): ?string
    {
        $val = config('settings.'.$key);
        if ($val === null || $val === '') {
            try {
                $val = PlatformSettings::legacy($key);
            } catch (\Throwable $e) {
                $val = null;
            }
        }

        return ($val === null || $val === '') ? null : (string) $val;
    }
}
