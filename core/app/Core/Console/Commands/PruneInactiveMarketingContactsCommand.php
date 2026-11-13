<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy notice §8: "Marketing contact data ... inactive contacts (no engagement) removed after 24 months" (P6-86).
 *
 * 1. (only when MARKETING_PRUNE_SUBSCRIBERS=true) newsletter_subscribers (the platform list: email + joined date only, no engagement is recorded): rows joined more
 *    than 24 months ago are deleted.
 * 2. customers (each store's own list, per store): a customer with a marketing or SMS opt-in whose latest activity
 *    (order, consent, record update, sign-up) is older than 24 months has the opt-ins switched off. The customer row,
 *    orders and consent_at (proof of when consent was given) are kept; nothing is deleted.
 */
class PruneInactiveMarketingContactsCommand extends Command
{
    public const INACTIVE_MONTHS = 24;

    protected $signature = 'marketing:prune-inactive-contacts {--dry-run : Count only, change nothing}';

    protected $description = 'Remove marketing contacts inactive for 24 months (Privacy notice section 8)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $cutoff = now()->subMonths(self::INACTIVE_MONTHS);
        $verb = $dry ? 'would be' : 'were';

        if (config('legal.prune_subscribers') && Schema::hasTable('newsletter_subscribers')) {
            $q = DB::table('newsletter_subscribers')->whereNotNull('joined')->where('joined', '<', $cutoff->toDateString());
            $n = $dry ? $q->count() : $q->delete();
            $this->info("Newsletter subscribers {$verb} deleted: {$n}.");
        }

        if (Schema::hasTable('customers')) {
            // Inactive = every activity timestamp is older than the cutoff (NULL counts as old).
            $q = DB::table('customers')
                ->where(fn ($w) => $w->where('marketing_consent', true)->orWhere('sms_consent', true));
            foreach (['last_order_at', 'consent_at', 'updated_at', 'created_at'] as $col) {
                $q->where(fn ($w) => $w->whereNull($col)->orWhere($col, '<', $cutoff));
            }
            $n = $dry ? $q->count() : $q->update(['marketing_consent' => false, 'sms_consent' => false]);
            $this->info("Store customer marketing opt-ins {$verb} switched off: {$n}.");
        }

        return self::SUCCESS;
    }
}
