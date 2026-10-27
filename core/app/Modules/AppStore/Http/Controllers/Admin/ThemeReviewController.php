<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Controllers\Admin;

use App\Jobs\RunThemeLighthouseGate;
use App\Models\MarketplaceMedia;
use App\Models\Theme;
use App\Models\ThemeVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\AppStore\Models\ThemePurchase;

/**
 * P4-4: the human end of the review pipeline (cloned from the MenuTemplate
 * admin pattern). Versions arrive with their automated lint/gate reports;
 * the reviewer approves (publish) or rejects with notes. Published versions
 * are immutable from that moment (ThemeVersion model guard).
 *
 * stores P1-4: the same admin surface owns each theme's STORE LISTING metadata
 * — category, tagline, support link, and the screenshot gallery (upload / move /
 * delete). Listing edits never touch commerce or the review status.
 */
class ThemeReviewController extends Controller
{
    public function index()
    {
        $pending = ThemeVersion::with('theme')
            ->where('status', 'review')->orderBy('created_at')->get();
        $recent = ThemeVersion::with('theme')
            ->whereIn('status', ['published', 'rejected', 'delisted'])
            ->orderByDesc('updated_at')->limit(20)->get();
        $themes = Theme::withCount('screenshots')->orderBy('name')->get();

        // P6-243: recent paid licences, refundable from here.
        $purchases = ThemePurchase::with(['theme', 'user'])
            ->where('price_paid_minor', '>', 0)->orderByDesc('id')->limit(30)->get();

        return view('admin.theme_reviews.index', compact('pending', 'recent', 'themes', 'purchases'));
    }

    /* ---- stores P1-4: listing metadata + screenshots ---- */

    public function listing(int $theme)
    {
        $theme = Theme::with(['media' => fn ($q) => $q->orderBy('position')])->findOrFail($theme);
        $industries = config('marketplace.theme_industries');

        return view('admin.theme_reviews.listing', compact('theme', 'industries'));
    }

    public function saveListing(Request $request, int $theme)
    {
        $theme = Theme::findOrFail($theme);

        $data = $request->validate([
            'category' => ['nullable', Rule::in(config('marketplace.theme_industries'))],
            'tagline' => ['nullable', 'string', 'max:140'],
            'support_url' => ['nullable', 'url', 'max:255'],
            'demo_notes' => ['nullable', 'string', 'max:255'],
        ]);

        // Listing edit ONLY — never touches status/price/version.
        $theme->update($data);

        app_alert_success(___('Listing updated.'));

        return redirect()->route('admin.theme-reviews.listing', $theme->id);
    }

    public function uploadMedia(Request $request, int $theme)
    {
        $theme = Theme::findOrFail($theme);

        $max = (int) config('marketplace.media.max_screenshots', 8);
        $mimes = implode(',', config('marketplace.media.mimes', ['jpg', 'jpeg', 'png', 'webp']));
        $maxKb = (int) config('marketplace.media.max_kb', 4096);
        $remaining = max($max - $theme->screenshots()->count(), 0);

        $request->validate([
            'screenshots' => ['required', 'array', 'max:'.$remaining],
            'screenshots.*' => ['image', "mimes:$mimes", "max:$maxKb"],
        ], [
            'screenshots.max' => ___('Screenshot limit reached. Maximum is :max.', ['max' => $max]),
        ]);

        // Generated filenames only, never a user-controlled path.
        $path = "storage/marketplace/theme/{$theme->id}/";
        $position = (int) $theme->media()->max('position');

        foreach ($request->file('screenshots') as $file) {
            $filename = image_upload($file, $path);
            $theme->media()->create([
                'disk_path' => $path.$filename,
                'position' => ++$position,
                'kind' => 'screenshot',
            ]);
        }

        app_alert_success(___('Screenshots uploaded.'));

        return redirect()->route('admin.theme-reviews.listing', $theme->id);
    }

    public function deleteMedia(int $media)
    {
        $media = MarketplaceMedia::findOrFail($media);
        $themeId = $media->mediable_id;

        remove_file($media->disk_path);
        $media->delete();

        app_alert_success(___('Screenshot removed.'));

        return redirect()->route('admin.theme-reviews.listing', $themeId);
    }

    public function moveMedia(Request $request, int $theme)
    {
        $theme = Theme::findOrFail($theme);
        $data = $request->validate([
            'media_id' => ['required', 'integer'],
            'dir' => ['required', 'in:up,down'],
        ]);

        $ids = $theme->media()->orderBy('position')->pluck('id')->all();
        $idx = array_search($data['media_id'], $ids, true);
        if ($idx === false) {
            return redirect()->route('admin.theme-reviews.listing', $theme->id);
        }
        $swap = $data['dir'] === 'up' ? $idx - 1 : $idx + 1;
        if ($swap >= 0 && $swap < count($ids)) {
            [$ids[$idx], $ids[$swap]] = [$ids[$swap], $ids[$idx]];
            foreach ($ids as $i => $id) {
                $theme->media()->where('id', $id)->update(['position' => $i]);
            }
        }

        return redirect()->route('admin.theme-reviews.listing', $theme->id);
    }

    public function approve(int $id)
    {
        $version = ThemeVersion::with('theme')->where('status', 'review')->findOrFail($id);

        $version->update(['status' => 'published']);
        $version->theme->update(['status' => 'published']);

        app_alert_success(___('Theme version published.'));

        return redirect()->route('admin.theme-reviews.index');
    }

    /**
     * Theme Studio follow-ups: queue the Lighthouse gate again for a version
     * still in review (e.g. after installing Lighthouse on the host, or when a
     * run was skipped). The report is cleared to "pending" until it lands.
     */
    public function rerunGate(int $id): RedirectResponse
    {
        $version = ThemeVersion::where('status', 'review')->findOrFail($id);

        $version->gate_report = null;
        $version->save();
        dispatch_job_reliably(new RunThemeLighthouseGate($version->id));

        app_alert_success(___('Quality gate queued.'));

        return redirect()->route('admin.theme-reviews.index');
    }

    public function reject(Request $request, int $id)
    {
        $request->validate(['notes' => 'required|string|max:2000']);
        $version = ThemeVersion::where('status', 'review')->findOrFail($id);

        $version->update([
            'status' => 'rejected',
            'review_notes' => $request->input('notes'),
        ]);

        app_alert_success(___('Theme version rejected.'));

        return redirect()->route('admin.theme-reviews.index');
    }
}
