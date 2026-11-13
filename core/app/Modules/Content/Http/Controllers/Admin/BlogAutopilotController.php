<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Jobs\GenerateBlogPostJob;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogTopic;
use Juwri\Modules\Content\Services\Blog\Autopilot\UsageStats;
use Juwri\Modules\Content\Services\Blog\Autopilot\WriterContract;
use Validator;

/**
 * BLOG-AUTOPILOT B2/B3: superadmin surface for the platform blog autopilot.
 *
 * One tab under the blog admin: a settings card (persisted through the generic
 * admin.settings.update Option path — same precedent as pricing_compare_on_home)
 * plus the topic backlog (add / ideas / draft-now / reject / retry) and a review
 * strip of pending autopilot drafts. Approval itself stays on the EXISTING blog
 * edit page — this tab links there and builds no second publish UI.
 *
 * Platform topics only (reseller_id NULL). Reseller topics live behind the
 * reseller portal endpoints, never in this panel.
 */
class BlogAutopilotController extends Controller
{
    public function __construct()
    {
        // Same gate as Admin\BlogController: no blog module, no autopilot tab. P1-16: a controller middleware
        // like Admin\BlogController's, not a constructor abort (`artisan route:list` instantiates controllers).
        $this->middleware(function ($request, \Closure $next) {
            abort_if(! config('settings.blog_enable'), 404);

            return $next($request);
        });
    }

    public function index(): View
    {
        $topics = BlogTopic::platform()->orderByDesc('id')->limit(200)->get();

        // Review strip: pending platform posts that came out of an autopilot
        // topic. Approval = the existing admin blog edit page (publish flow).
        $pendingReview = Blog::where('status', 'pending')
            ->whereIn('id', BlogTopic::platform()->whereNotNull('blog_id')->select('blog_id'))
            ->orderByDesc('id')
            ->get();

        // "Drafted this week / cap" counter — mirrors the A7 cap window:
        // topics flipped to 'published' inside the rolling 7 days.
        $publishedThisWeek = BlogTopic::platform()
            ->where('status', 'published')
            ->where('updated_at', '>=', now()->subDays(7))
            ->count();
        $weeklyCap = (int) (config('settings.blog_autopilot_weekly_cap')
            ?: config('blog_autopilot.weekly_publish_cap', 3));

        $languages = Language::where('active', 1)->orderBy('position')->get();
        $admins = User::where('user_type', 'admin')->orderBy('name')->get();

        // What autopilot is costing: calls + tokens per window (feature-tagged
        // ai_usage rollup), plus an optional estimated spend.
        $usage = new UsageStats()->platform();

        return view('admin.blog.autopilot', compact(
            'topics', 'pendingReview', 'publishedThisWeek', 'weeklyCap', 'languages', 'admins', 'usage'
        ));
    }

    /** B3: manual topic into the backlog (source=manual, status=queued). */
    public function storeTopic(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'min:2', 'max:500'],
            'keyword' => ['nullable', 'string', 'max:255'],
            'angle' => ['nullable', 'string', 'max:500'],
            'locale' => ['nullable', 'string', 'max:12'],
        ]);
        if ($validator->fails()) {
            app_alert_error(implode('<br>', $validator->errors()->all()));

            return back()->withInput();
        }

        BlogTopic::create([
            'reseller_id' => null,
            'title' => $request->title,
            'keyword' => $request->keyword,
            'angle' => $request->angle,
            'locale' => $request->locale ?: 'en',
            'status' => 'queued',
            'source' => 'manual',
        ]);

        app_alert_success(___('Topic added to the queue.'));

        return back();
    }

    /** B3: ask the writing engine for ideas; persist each as source=ai. */
    public function generateIdeas(Request $request): RedirectResponse
    {
        $count = max(1, min(10, (int) $request->input('count', 5)));
        $locale = (string) ($request->input('locale') ?: 'en');

        try {
            $ideas = app(WriterContract::class)->suggestTopics($count, null);
        } catch (\Throwable $e) {
            report($e);
            app_alert_error(___('Could not generate ideas — no AI writing engine is available.'));

            return back();
        }

        $created = 0;
        foreach ($ideas as $idea) {
            $title = trim((string) $idea['title']);
            if ($title === '') {
                continue;
            }
            BlogTopic::create([
                'reseller_id' => null,
                'title' => mb_substr($title, 0, 500),
                'keyword' => mb_substr(trim((string) $idea['keyword']), 0, 255) ?: null,
                'angle' => mb_substr(trim((string) $idea['angle']), 0, 500) ?: null,
                'locale' => $locale,
                'status' => 'queued',
                'source' => 'ai',
            ]);
            $created++;
        }

        app_alert_success(___('Ideas added to the queue.').' ('.$created.')');

        return back();
    }

    /** B3: dispatch the generation job for one queued/failed platform topic. */
    public function draftNow(BlogTopic $topic): RedirectResponse
    {
        abort_if($topic->reseller_id !== null, 404);

        if (! in_array($topic->status, ['queued', 'failed'], true)) {
            app_alert_error(___('Only a queued or failed topic can be drafted.'));

            return back();
        }

        GenerateBlogPostJob::dispatch($topic->id);
        app_alert_success(___('Draft job queued.'));

        return back();
    }

    /** B3: reject a topic (kept as an audit row, never deleted). */
    public function reject(BlogTopic $topic): RedirectResponse
    {
        abort_if($topic->reseller_id !== null, 404);

        if ($topic->status === 'published') {
            app_alert_error(___('A published topic can not be rejected.'));

            return back();
        }

        $topic->update(['status' => 'rejected']);
        app_alert_success(___('Topic rejected.'));

        return back();
    }

    /** B3: put a failed topic back into the queue. */
    public function retry(BlogTopic $topic): RedirectResponse
    {
        abort_if($topic->reseller_id !== null, 404);

        if ($topic->status !== 'failed') {
            app_alert_error(___('Only a failed topic can be retried.'));

            return back();
        }

        $topic->update(['status' => 'queued', 'error' => null]);
        app_alert_success(___('Topic re-queued.'));

        return back();
    }
}
