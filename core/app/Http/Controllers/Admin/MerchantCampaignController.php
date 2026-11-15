<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Admin;

use App\Jobs\DispatchMerchantCampaign;
use App\Mails\MerchantCampaignMail;
use App\Models\MerchantCampaignSend;
use App\Services\MerchantCampaign;
use App\Support\SearchTerm;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Policies\Admin\PanelAbilities;

/**
 * Admin bulk-email campaigns to the platform's merchants.
 *
 * The same engine the trial reminders use, so a campaign here is batched,
 * idempotent per merchant, rate limited, opt-out aware and logged — and a
 * campaign can equally be run from the command line:
 *
 *   php artisan merchants:campaign <key> --dry-run
 */
class MerchantCampaignController extends Controller
{
    public function __construct(protected MerchantCampaign $campaigns) {}

    /** The form: the segment, the copy, and what a send would do. */
    public function index(Request $request): View
    {
        $this->authorize(PanelAbilities::ACCOUNTS);

        $criteria = $this->criteria($request);
        $key = $this->campaignKey($request);
        $preview = $this->campaigns->preview($criteria + ['campaign' => $key]);

        return view('admin.merchants.campaign', [
            'campaign' => $key,
            'criteria' => $criteria,
            'preview' => $preview,
            'plans' => app(PlanCatalog::class)->plansByPosition(),
            'countries' => DB::table('users')
                ->select('country')->whereNotNull('country')->where('country', '<>', '')
                ->distinct()->orderBy('country')->pluck('country'),
            'subject' => (string) $request->input('subject', config('settings.email_sub_merchant_campaign', '')),
            'body' => (string) $request->input('body', config('settings.email_message_merchant_campaign', '')),
            'reports' => $this->reports(),
            'pending' => $this->campaigns->pendingJobs($key),
            'scheduled' => $this->campaigns->scheduledCampaigns(),
        ]);
    }

    /** The live counts for the segment, without sending anything. */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize(PanelAbilities::ACCOUNTS);

        $criteria = $this->criteria($request);
        $key = $this->campaignKey($request);

        return response()->json([
            'success' => true,
            'preview' => $this->campaigns->preview($criteria + ['campaign' => $key]),
        ]);
    }

    /** Queue the campaign, now or at a chosen time. */
    public function send(Request $request): JsonResponse
    {
        $this->authorize(PanelAbilities::ACCOUNTS);

        $validated = $request->validate([
            'campaign' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9\-_]+$/i'],
            'subject' => ['nullable', 'string', 'max:190'],
            'body' => ['nullable', 'string', 'max:20000'],
            'per_minute' => ['nullable', 'integer', 'min:1', 'max:600'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $key = (string) $validated['campaign'];
        $criteria = $this->criteria($request);

        /* Never send a campaign to nobody: a preview that matched zero people
           is a mistake, not a campaign. */
        $preview = $this->campaigns->preview($criteria + ['campaign' => $key]);
        if ($preview['to_send'] < 1) {
            return response()->json([
                'success' => false,
                'message' => ___('This segment has nobody left to reach.'),
            ], 422);
        }

        $scheduledAt = $this->scheduledAt($validated['scheduled_at'] ?? null);

        $job = new DispatchMerchantCampaign(
            $criteria,
            $key,
            $validated['subject'] ?? null,
            $validated['body'] ?? null,
            (int) ($validated['per_minute'] ?? 60),
        );

        if ($scheduledAt !== null) {
            /* The job starts the campaign at that time; the mails it queues
               then spread out from there. */
            dispatch($job)->delay($scheduledAt);
        } else {
            dispatch_job_reliably($job);
        }

        return response()->json([
            'success' => true,
            'message' => $scheduledAt !== null
                ? ___('Scheduled :count emails for :when.', [
                    'count' => $preview['to_send'],
                    'when' => $scheduledAt->format('Y-m-d H:i'),
                ])
                : ___('Queued :count emails.', ['count' => $preview['to_send']]),
            'campaign' => $key,
            'to_send' => $preview['to_send'],
            'scheduled_at' => $scheduledAt?->timestamp,
        ]);
    }

    /**
     * The merchants an operator can pick from.
     *
     * The filters build a segment; this finds the individual merchants in
     * it — the ones with an address, so they can actually be written to.
     */
    public function merchants(Request $request): JsonResponse
    {
        $this->authorize(PanelAbilities::ACCOUNTS);

        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = SearchTerm::clean((string) $request->query('q', ''));
        $limit = (int) ($request->query('limit') ?: 50);

        $query = User::query()
            ->select('id', 'name', 'username', 'email', 'country')
            ->where('user_type', 'user')
            ->where('status', true)
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->when($search !== '', function ($q) use ($search): void {
                $q->where(fn ($inner) => SearchTerm::apply($inner, ['name', 'username', 'email'], $search));
            })
            ->orderBy('id')
            ->limit($limit);

        $merchants = $query->get();
        $ids = $merchants->pluck('id')->all();

        $stores = Store::query()
            ->whereIn('user_id', $ids)
            ->get(['user_id', 'title'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->first()?->title);

        $plans = collect();
        if (Schema::hasTable('subscriptions') && $ids !== []) {
            $plans = DB::table('subscriptions')
                ->whereIn('user_id', $ids)
                ->orderBy('id')
                ->get()
                ->groupBy('user_id')
                ->map(function ($rows): string {
                    $row = $rows->last();
                    if (($row->interval_ ?? '') === 'trial') {
                        return 'trial';
                    }

                    return $row->plan_id !== null ? (string) $row->plan_id : 'free';
                });
        }

        return response()->json([
            'success' => true,
            'merchants' => $merchants->map(function (User $user) use ($stores, $plans): array {
                return [
                    'id' => $user->id,
                    'name' => $user->name ?: $user->username,
                    'email' => $user->email,
                    'country' => $user->country,
                    'store' => $stores[$user->id] ?? null,
                    'plan' => $plans[$user->id] ?? 'free',
                ];
            })->values(),
        ]);
    }

    /**
     * The chosen time, in the app's own timezone.
     *
     * A time in the past means "now": the operator's clock and the server's
     * are not always the same, and a campaign should never be silently lost.
     */
    protected function scheduledAt(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            $when = Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }

        return $when->isFuture() ? $when : null;
    }

    /**
     * Stop a campaign that is still queued.
     *
     * The mails of a campaign are rate limited, so most of them are still
     * waiting in the queue when the operator realises they queued the wrong
     * segment. This takes those mails out of the queue and marks the log.
     */
    public function cancel(Request $request): JsonResponse
    {
        $this->authorize(PanelAbilities::ACCOUNTS);

        $validated = $request->validate([
            'campaign' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9\-_]+$/i'],
        ]);

        $key = (string) $validated['campaign'];
        $result = $this->campaigns->cancel($key);

        return response()->json([
            'success' => true,
            'campaign' => $key,
            'stopped_jobs' => $result['jobs'],
            'stopped_rows' => $result['rows'],
            'stopped_scheduled' => $result['scheduled'],
            'message' => ___('Stopped :count queued emails.', ['count' => $result['jobs']]),
        ]);
    }

    /**
     * Send the copy to the operator first.
     *
     * A campaign reaches every merchant in its segment, so it has to be seen
     * before it is sent. This delivers the same message to the signed-in
     * admin only.
     */
    public function testSend(Request $request): JsonResponse
    {
        $this->authorize(PanelAbilities::ACCOUNTS);

        $validated = $request->validate([
            'subject' => ['nullable', 'string', 'max:190'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        /** @var User $admin */
        $admin = $request->user();

        $mailable = new MerchantCampaignMail(
            $admin->name ?: ($admin->username ?: 'there'),
            '',
            '',
            $validated['subject'] ?? null,
            $validated['body'] ?? null,
            $this->campaigns->unsubscribeUrl($admin),
            'test',
        );
        $mailable->to($admin->email);

        // Queued like a real campaign mail, so it goes out through the same
        // path the operator is about to use.
        Mail::queue($mailable);

        return response()->json([
            'success' => true,
            'message' => ___('A test email is on its way to :email.', ['email' => $admin->email]),
        ]);
    }

    /** What a campaign actually did, per merchant. */
    public function report(Request $request): View
    {
        $this->authorize(PanelAbilities::ACCOUNTS);

        $key = (string) $request->query('campaign', '');
        $rows = MerchantCampaignSend::query()
            ->when($key !== '', fn ($q) => $q->where('campaign', $key))
            ->with('user:id,username,name,email')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $totals = MerchantCampaignSend::query()
            ->when($key !== '', fn ($q) => $q->where('campaign', $key))
            ->select('status', DB::raw('count(*) as n'))
            ->groupBy('status')
            ->pluck('n', 'status')
            ->toArray();

        return view('admin.merchants.campaign-report', [
            'campaign' => $key,
            'rows' => $rows,
            'totals' => $totals,
            'campaigns' => MerchantCampaignSend::query()->select('campaign')->distinct()->pluck('campaign'),
            'pending' => $key !== '' ? $this->campaigns->pendingJobs($key) : 0,
            'scheduled' => $this->campaigns->scheduledCampaigns(),
        ]);
    }

    /**
     * The segment, as the form (or the command line) asked for it.
     *
     * @return array<string, mixed>
     */
    protected function criteria(Request $request): array
    {
        $ids = $request->input('user_ids', '');
        if (is_array($ids)) {
            $ids = implode(',', $ids);
        }

        return [
            'filters' => [
                'plan' => (string) $request->input('plan', 'any'),
                'country' => (string) $request->input('country', 'any'),
                'trial' => (string) $request->input('trial', 'any'),
                'trial_within' => (int) $request->input('trial_within', 7),
                'has_subscription' => (string) $request->input('has_subscription', ''),
            ],
            'user_ids' => array_filter(array_map('intval', explode(',', (string) $ids))),
            'limit' => (int) $request->input('limit', 0) ?: null,
            // not part of the segment: kept so the form still shows what was
            // typed when the page comes back.
            'scheduled_at' => (string) $request->input('scheduled_at', ''),
            'merchant_search' => (string) $request->input('merchant_search', ''),
        ];
    }

    protected function campaignKey(Request $request): string
    {
        $key = trim((string) $request->input('campaign', ''));

        return $key !== '' ? $key : 'merchant-campaign';
    }

    /**
     * The campaigns that have run, with their totals.
     *
     * @return array<int, MerchantCampaignSend>
     */
    protected function reports(): array
    {
        return MerchantCampaignSend::query()
            ->select('campaign', DB::raw('count(*) as total'))
            ->selectRaw("sum(case when status = 'sent' then 1 else 0 end) as sent")
            ->selectRaw("sum(case when status = 'skipped_optout' then 1 else 0 end) as opted_out")
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failed")
            ->groupBy('campaign')
            ->orderByDesc('total')
            ->limit(20)
            ->get()
            ->all();
    }
}
