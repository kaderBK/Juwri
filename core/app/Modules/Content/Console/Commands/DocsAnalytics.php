<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Console\Commands;

use Juwri\Modules\Content\Models\DocsEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOCS-PLATFORM D6-2: report docs-quality signals — the failing pages (most 👎
 * votes) and top zero-result searches — plus a deflection estimate: of the
 * signed-in owners who used the docs in the window, how many did NOT open a
 * support ticket within 72h (a proxy for "the docs answered it").
 */
class DocsAnalytics extends Command
{
    protected $signature = 'docs:analytics {--days=30 : window to report over} {--top=10 : rows per section}';

    protected $description = 'Report docs failing pages, zero-result searches, and a deflection estimate';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $top = max(1, (int) $this->option('top'));
        $since = now()->subDays($days);

        $events = DocsEvent::where('created_at', '>=', $since);
        $total = (clone $events)->count();
        $this->info("Docs signals in the last {$days} days: {$total}");
        $this->newLine();

        // Failing pages: most 👎 by pillar+slug.
        // toBase(): these are grouped-aggregate rows (the `n` count is not a model
        // column), so read them as plain stdClass instead of DocsEvent models.
        $unhelpful = (clone $events)->where('type', 'unhelpful')
            ->select('pillar', 'slug', DB::raw('count(*) as n'))
            ->groupBy('pillar', 'slug')->orderByDesc('n')->limit($top)->toBase()->get();
        $this->line('<comment>Failing pages (most "not helpful")</comment>');
        $this->table(['Pillar', 'Page', '👎'], $unhelpful->map(fn ($r) => [$r->pillar, $r->slug ?? '—', $r->n])->all() ?: [['—', '—', 0]]);

        // Zero-result searches: the docs-gap queries.
        $zeros = (clone $events)->where('type', 'search_zero')
            ->select('meta', DB::raw('count(*) as n'))
            ->groupBy('meta')->orderByDesc('n')->limit($top)->toBase()->get();
        $this->newLine();
        $this->line('<comment>Top zero-result searches</comment>');
        $this->table(['Query', 'Count'], $zeros->map(fn ($r) => [$r->meta ?? '—', $r->n])->all() ?: [['—', 0]]);

        // Deflection: signed-in docs users with no support ticket within 72h.
        $this->newLine();
        if (! Schema::hasTable('support_tickets')) {
            $this->line('Deflection: support_tickets table absent — skipping.');

            return self::SUCCESS;
        }
        $docUsers = (clone $events)->whereNotNull('user_id')
            ->select('user_id', DB::raw('min(created_at) as first_seen'))
            ->groupBy('user_id')->toBase()->get();
        $deflected = 0;
        foreach ($docUsers as $u) {
            $openedTicket = DB::table('support_tickets')
                ->where('user_id', $u->user_id)
                ->whereBetween('created_at', [$u->first_seen, Carbon::parse($u->first_seen)->addHours(72)])
                ->exists();
            if (! $openedTicket) {
                $deflected++;
            }
        }
        $n = $docUsers->count();
        $pct = $n ? round($deflected / $n * 100, 1) : 0.0;
        $this->line("Deflection (signed-in docs users, no ticket within 72h): {$deflected}/{$n} = {$pct}%");
        $this->comment('Note: public/anonymous docs views are not counted — deflection covers signed-in owners only.');

        return self::SUCCESS;
    }
}
