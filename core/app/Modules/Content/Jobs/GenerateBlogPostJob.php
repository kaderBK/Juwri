<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Juwri\Modules\Content\Models\BlogTopic;
use Juwri\Modules\Content\Services\Blog\Autopilot\WriterContract;

/**
 * Blog Autopilot: turn one queued BlogTopic into a full draft post via the
 * WriterContract engine. Runs on the heavy lane (long multi-call LLM work).
 * Idempotent on replay: only a 'queued' or 'failed' topic is picked up, so a
 * lost-response redelivery never double-writes a draft.
 */
class GenerateBlogPostJob implements ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $topicId)
    {
        $this->onQueue(config('blog_autopilot.queue', 'heavy'));
    }

    public function handle(WriterContract $writer): void
    {
        // Atomic claim: flip queued|failed -> drafting in ONE UPDATE and act only
        // if we won the row. This closes the double-draft race where the
        // scheduler re-dispatches a topic that draftNow left 'queued', or two
        // heavy workers pick the same row — otherwise both would writeDraft and
        // double-bill the AI budget.
        $claimed = BlogTopic::whereKey($this->topicId)
            ->whereIn('status', ['queued', 'failed'])
            ->update(['status' => 'drafting', 'error' => null]);

        if ($claimed !== 1) {
            return; // gone, already drafting/drafted, rejected, or claimed elsewhere
        }

        $topic = BlogTopic::find($this->topicId);
        if (! $topic) {
            return;
        }

        try {
            $writer->writeDraft($topic);
            // writeDraft() links blog_id / reseller_blog_post_id and sets the
            // topic status to 'drafted' itself.
        } catch (\Throwable $e) {
            $topic->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            report($e);
        }
    }

    /**
     * Queue failure hook: a hard timeout / SIGKILL / OOM skips handle()'s catch,
     * leaving the topic stuck in 'drafting'. This returns it to 'failed' so it is
     * visible and retryable (the scheduler's reaper is the backstop when the
     * worker dies so hard this never fires).
     */
    public function failed(\Throwable $e): void
    {
        BlogTopic::whereKey($this->topicId)
            ->where('status', 'drafting')
            ->update([
                'status' => 'failed',
                'error' => mb_substr('Job failed: '.$e->getMessage(), 0, 2000),
            ]);
    }
}
