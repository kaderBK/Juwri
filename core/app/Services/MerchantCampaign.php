<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Mails\MerchantCampaignMail;
use App\Models\MerchantCampaignSend;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Models\User;
use Juwri\Core\Support\Sql;

/**
 * Bulk email campaigns to the platform's own merchants.
 *
 * Modeled on the trial-reminder workflow (AppTrialsTick + AppTrialReminderMail)
 * and the WhatsApp broadcast (WhatsAppBroadcast), so the same guarantees hold:
 *
 *   - batched        chunkById, so a large segment never sits in memory
 *   - idempotent     one row per (user, campaign) in merchant_campaign_sends;
 *                     a second run over the same segment skips them
 *   - opt-out        a user option, set by a signed one-click link in the mail
 *   - rate limited   the mails are queued with a delay, so the SMTP provider
 *                     is not hit with the whole segment at once
 *   - auditable      every recipient gets a log row with the outcome
 *   - dry run        nothing is sent, the counts are still exact
 */
class MerchantCampaign
{
    /** The user option a merchant's "stop emailing me" link writes. */
    public const OPT_OUT_KEY = 'merchant_campaign_emails';

    /**
     * The segment: which merchants a campaign reaches.
     *
     * @param  array<string, mixed>  $criteria
     * @return Builder<User>
     */
    public function recipients(array $criteria): Builder
    {
        $filters = is_array($criteria['filters'] ?? null) ? $criteria['filters'] : [];

        $query = User::query()->select('users.*');

        if (! empty($criteria['user_ids']) && is_array($criteria['user_ids'])) {
            $ids = array_map('intval', $criteria['user_ids']);
            $query->whereIn('users.id', $ids);
        }

        /* a campaign is only worth sending to active merchants with an address */
        $query->where('users.user_type', 'user')
            ->where('users.status', true)
            ->whereNotNull('users.email')->where('users.email', '<>', '');

        $country = (string) ($filters['country'] ?? '');
        if ($country !== '' && $country !== 'any') {
            $query->where('users.country', $country);
        }

        $hasSubscriptionsTable = Schema::hasTable('subscriptions');

        $plan = (string) ($filters['plan'] ?? '');
        if ($plan !== '' && $plan !== 'any') {
            if ($hasSubscriptionsTable) {
                $query->whereRaw('('.$this->planKeyExpression().') = ?', [$plan]);
            } elseif ($plan !== 'free') {
                $query->whereRaw('1 = 0');
            }
        }

        $trial = (string) ($filters['trial'] ?? '');
        if ($trial !== '' && $trial !== 'any') {
            if (! $hasSubscriptionsTable) {
                $query->whereRaw('1 = 0');
            } else {
                $endExpr = $this->planEndExpression();
                if ($trial === 'ending') {
                    $query->whereNotNull('users.id')
                        ->whereRaw("({$endExpr}) IS NOT NULL AND ({$endExpr}) BETWEEN ? AND ?", [
                            now()->getTimestamp(),
                            now()->addDays((int) ($filters['trial_within'] ?? 7))->getTimestamp(),
                        ]);
                } elseif ($trial === 'ended') {
                    $query->whereRaw("({$endExpr}) IS NOT NULL AND ({$endExpr}) < ?", [now()->getTimestamp()]);
                }
            }
        }

        /* a plan the merchant actually holds a subscription for */
        if (($filters['has_subscription'] ?? '') === '1') {
            if (! $hasSubscriptionsTable) {
                $query->whereRaw('1 = 0');
            } else {
                /* The query builder prefixes table and column names itself, so the
                   names here stay bare. Adding the prefix by hand asks a prefixed
                   database for double-prefixed tables. Only the raw SQL below needs
                   the prefix written out. */
                $query->whereExists(function (QueryBuilder $q): void {
                    $q->select(DB::raw(1))->from('subscriptions')
                        ->whereColumn('subscriptions.user_id', 'users.id');
                });
            }
        }

        $query->orderBy('users.id');

        if (! empty($criteria['limit'])) {
            $query->limit((int) $criteria['limit']);
        }

        return $query;
    }

    /**
     * The preview: exactly what a send would do, without doing it.
     *
     * @param  array<string, mixed>  $criteria
     * @return array{total:int,already:int,opted_out:int,no_email:int,to_send:int}
     */
    public function preview(array $criteria): array
    {
        /* The count has to respect the limit, because the send does. A plain
           count() drops the LIMIT (MySQL ignores it in an aggregate), so the
           operator would be shown the whole segment while only the capped
           part goes out. Counting a derived table keeps the limit in play. */
        $total = DB::query()
            ->fromSub((clone $this->recipients($criteria))->select('users.id'), 'campaign_segment')
            ->count();

        $already = 0;
        $optedOut = 0;
        $noEmail = 0;

        $campaign = isset($criteria['campaign']) ? (string) $criteria['campaign'] : null;
        $this->recipients($criteria)->chunkById(200, function ($users) use (&$already, &$optedOut, &$noEmail, $campaign): void {
            foreach ($users as $user) {
                if (! $user->email) {
                    $noEmail++;

                    continue;
                }
                if ($campaign && MerchantCampaignSend::where('user_id', $user->id)->where('campaign', $campaign)->exists()) {
                    $already++;

                    continue;
                }
                if (! $this->wantsCampaignEmails($user)) {
                    $optedOut++;
                }
            }
        });

        return [
            'total' => $total,
            'already' => $already,
            'opted_out' => $optedOut,
            'no_email' => $noEmail,
            'to_send' => max(0, $total - $already - $optedOut - $noEmail),
        ];
    }

    /**
     * Send a campaign.
     *
     * @param  array<string, mixed>  $criteria
     * @return array{considered:int,sent:int,skipped_optout:int,skipped_duplicate:int,failed:int}
     */
    public function send(array $criteria, string $campaign, ?string $subject = null, ?string $body = null, bool $dryRun = false, int $perMinute = 60): array
    {
        $stats = ['considered' => 0, 'sent' => 0, 'skipped_optout' => 0, 'skipped_duplicate' => 0, 'failed' => 0];
        $perMinute = max(1, $perMinute);
        $offset = 0;

        $this->recipients($criteria)->chunkById(200, function ($users) use (&$stats, &$offset, $campaign, $subject, $body, $dryRun, $perMinute): void {
            foreach ($users as $user) {
                $stats['considered']++;

                if (! $user->email) {
                    continue;
                }

                if (! $this->wantsCampaignEmails($user)) {
                    if ($dryRun) {
                        if (MerchantCampaignSend::where('user_id', $user->id)->where('campaign', $campaign)->exists()) {
                            $stats['skipped_duplicate']++;
                        } else {
                            $stats['skipped_optout']++;
                        }

                        continue;
                    }

                    $now = now();
                    $inserted = DB::table('merchant_campaign_sends')->insertOrIgnore([
                        'user_id' => $user->id,
                        'campaign' => $campaign,
                        'status' => MerchantCampaignSend::STATUS_SKIPPED_OPTOUT,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    if ($inserted === 0) {
                        $stats['skipped_duplicate']++;
                    } else {
                        $stats['skipped_optout']++;
                    }

                    continue;
                }

                if ($dryRun) {
                    if (MerchantCampaignSend::where('user_id', $user->id)->where('campaign', $campaign)->exists()) {
                        $stats['skipped_duplicate']++;
                    } else {
                        $stats['sent']++;
                    }

                    continue;
                }

                /* idempotent: claim the (user, campaign) row first so concurrent runs cannot double-send */
                $now = now();
                $claimed = DB::table('merchant_campaign_sends')->insertOrIgnore([
                    'user_id' => $user->id,
                    'campaign' => $campaign,
                    'status' => MerchantCampaignSend::STATUS_QUEUED,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                if ($claimed === 0) {
                    $stats['skipped_duplicate']++;

                    continue;
                }

                try {
                    $mailable = new MerchantCampaignMail(
                        $user->name ?: ($user->username ?: 'there'),
                        $this->countryFor($user),
                        $this->planLabelFor($user),
                        $subject,
                        $body,
                        $this->unsubscribeUrl($user),
                        $campaign,
                    );
                    $mailable->to($user->email);
                    // spread the segment out so the provider is not hit with the
                    // whole list at once
                    $sendAt = now()->addSeconds((int) floor($offset * 60 / $perMinute));
                    $mailable->later($sendAt, app('queue'));

                    // sent_at = when the queued mail goes out; cancel() stops the rows still in the future.
                    MerchantCampaignSend::where('user_id', $user->id)
                        ->where('campaign', $campaign)
                        ->update([
                            'status' => MerchantCampaignSend::STATUS_SENT,
                            'sent_at' => $sendAt,
                            'error' => null,
                        ]);
                    $stats['sent']++;
                } catch (\Throwable $e) {
                    report($e);
                    MerchantCampaignSend::where('user_id', $user->id)
                        ->where('campaign', $campaign)
                        ->update([
                            'status' => MerchantCampaignSend::STATUS_FAILED,
                            'error' => substr($e->getMessage(), 0, 255),
                        ]);
                    $stats['failed']++;
                }
                $offset++;
            }
        });

        return $stats;
    }

    /**
     * Stop a campaign that is still queued.
     *
     * A campaign is rate limited, so its mails sit in the queue for hours.
     * This removes the ones that have not gone out yet and marks the log, so
     * the operator can stop a campaign they queued by mistake without
     * touching the jobs of the rest of the platform.
     *
     * A campaign that is still waiting for its scheduled time is stopped too:
     * the job that would have started it is taken out of the queue.
     *
     * @return array{jobs:int,rows:int,scheduled:int}
     */
    public function cancel(string $campaign): array
    {
        $jobs = $this->pendingJobQuery($campaign)->delete();
        $scheduled = $this->pendingDispatchQuery($campaign)->delete();

        // Rows still claimed, or queued for a time that has not come yet, never went out.
        $rows = MerchantCampaignSend::where('campaign', $campaign)
            ->where(fn ($q) => $q->where('status', MerchantCampaignSend::STATUS_QUEUED)
                ->orWhere(fn ($q) => $q->where('status', MerchantCampaignSend::STATUS_SENT)->where('sent_at', '>', now())))
            ->update(['status' => MerchantCampaignSend::STATUS_STOPPED]);

        return ['jobs' => $jobs, 'rows' => $rows, 'scheduled' => $scheduled];
    }

    /** How many mails of this campaign are still waiting to be sent. */
    public function pendingJobs(string $campaign): int
    {
        return $this->pendingJobQuery($campaign)->count();
    }

    /**
     * The campaigns that are waiting for their scheduled time.
     *
     * @return array<int,array{campaign:string,at:int}>
     */
    public function scheduledCampaigns(): array
    {
        $out = [];

        foreach ($this->pendingDispatchQuery('')->get() as $job) {
            $key = $this->campaignKeyFromPayload((string) $job->payload);
            if ($key === null) {
                continue;
            }
            $out[] = ['campaign' => $key, 'at' => (int) $job->available_at];
        }

        usort($out, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $out;
    }

    /**
     * The queued jobs that belong to one campaign.
     *
     * The queue row holds the job, and the job holds the mailable with its
     * campaign key. The key is matched together with the property name and
     * its length, so a campaign whose copy merely mentions another
     * campaign's key is not caught with it.
     */
    protected function pendingJobQuery(string $campaign): QueryBuilder
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%MerchantCampaignMail%')
            ->where(function (QueryBuilder $query) use ($campaign): void {
                foreach ($this->keyPatterns('campaignKey', $campaign) as $pattern) {
                    $query->orWhereRaw("payload LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
    }

    /**
     * The queued job that would START a campaign at its scheduled time.
     *
     * @param  string  $campaign  the campaign key, or '' for any of them
     */
    protected function pendingDispatchQuery(string $campaign): QueryBuilder
    {
        $query = DB::table('jobs')->where('payload', 'like', '%DispatchMerchantCampaign%');

        if ($campaign !== '') {
            $query->where(function (QueryBuilder $inner) use ($campaign): void {
                foreach ($this->keyPatterns('campaign', $campaign) as $pattern) {
                    $inner->orWhereRaw("payload LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
        }

        return $query;
    }

    /**
     * The two ways the campaign key can appear in a queue payload.
     *
     * @return array<int,string>
     */
    protected function keyPatterns(string $property, string $campaign): array
    {
        $key = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $campaign);
        $length = strlen($campaign);

        return [
            '%'.$property.'";s:'.$length.':"'.$key.'"%',
            '%'.$property.'\\";s:'.$length.':\\"'.$key.'\\"%',
        ];
    }

    /**
     * The campaign key inside a queue payload, if it carries one.
     */
    protected function campaignKeyFromPayload(string $payload): ?string
    {
        $raw = null;

        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            $raw = $decoded['data']['command'] ?? null;
        }

        $source = is_string($raw) && $raw !== '' ? $raw : $payload;

        if (preg_match('/s:8:"campaign";s:(\d+):"/', $source, $match, PREG_OFFSET_CAPTURE) === 1) {
            $length = (int) $match[1][0];
            $start = $match[0][1] + strlen($match[0][0]);

            if ($start + $length <= strlen($source) && substr($source, $start + $length, 2) === '";') {
                return substr($source, $start, $length);
            }
        }

        return null;
    }

    /** Has this merchant asked us to stop emailing them? */
    public function wantsCampaignEmails(User $user): bool
    {
        try {
            return user_options($user->id, self::OPT_OUT_KEY) !== '0';
        } catch (\Throwable $e) {
            return true;
        }
    }

    /** The signed one-click unsubscribe link (works from an inbox, no session). */
    public function unsubscribeUrl(User $user): string
    {
        if (! Route::has('campaigns.merchant.unsubscribe')) {
            return Route::has('dashboard') ? route('dashboard') : url('/');
        }

        return URL::signedRoute('campaigns.merchant.unsubscribe', ['user' => $user->id]);
    }

    /** The country the merchant registered with, for the {COUNTRY} shortcode. */
    protected function countryFor(User $user): string
    {
        return trim((string) $user->country) ?: trim((string) $user->country_code);
    }

    /** The merchant's plan label, for the {PLAN} shortcode. */
    protected function planLabelFor(User $user): string
    {
        $key = $this->planKeyFor($user);
        if ($key === 'free') {
            return ___('Free');
        }
        if ($key === 'trial') {
            return ___('Trial');
        }
        if (is_numeric($key)) {
            $plan = app(PlanCatalog::class)->find((int) $key);
            if ($plan) {
                return (string) $plan->getAttribute('name');
            }
        }

        return $key;
    }

    /** 'trial' | plan id | 'free', from the merchant's latest subscription. */
    public function planKeyFor(User $user): string
    {
        $row = app(PlanCatalog::class)->lastSubscription($user);

        if (! $row) {
            return 'free';
        }
        if (((string) $row->getAttribute('interval_')) === 'trial') {
            return 'trial';
        }
        $planId = $row->getAttribute('plan_id');
        if (! empty($planId)) {
            return (string) $planId;
        }

        return 'free';
    }

    /**
     * The SQL that yields a merchant's plan key from their latest
     * subscription — the same expression the admin users list uses.
     */
    protected function planKeyExpression(): string
    {
        $prefix = DB::getTablePrefix();

        return sprintf(
            "SELECT %s FROM {$prefix}subscriptions WHERE {$prefix}subscriptions.user_id = {$prefix}users.id ORDER BY id DESC LIMIT 1",
            "CASE WHEN interval_ = 'trial' THEN 'trial' WHEN plan_id IS NOT NULL THEN ".Sql::castText('plan_id')." ELSE 'free' END"
        );
    }

    /** The SQL that yields the unix timestamp a merchant's plan ends. */
    protected function planEndExpression(): string
    {
        $prefix = DB::getTablePrefix();
        $ux = static fn (string $col): string => Sql::epoch($col);

        return sprintf(
            "SELECT %s FROM {$prefix}subscriptions WHERE {$prefix}subscriptions.user_id = {$prefix}users.id ORDER BY id DESC LIMIT 1",
            "CASE WHEN ends_at IS NULL AND interval_ = 'lifetime' THEN 8000000000 "
            ."WHEN status = 'expired' AND (ends_at IS NULL OR ends_at > CURRENT_TIMESTAMP) THEN ".$ux('updated_at').' '
            .'ELSE '.$ux('ends_at').' END'
        );
    }
}
