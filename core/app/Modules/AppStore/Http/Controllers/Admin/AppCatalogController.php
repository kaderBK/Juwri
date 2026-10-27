<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Controllers\Admin;

use App\Models\App;
use App\Models\AppInstallation;
use App\Services\AppInstallService;
use App\Support\SearchTerm;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Feature;
use Juwri\Modules\AppStore\Models\AppVersion;

/**
 * Superadmin catalog editor for App Store listings.
 *
 * AppReviewController is the approve/reject GATE; this is the LISTING itself —
 * commercials (billing type, price, period, trial, usage rates), storefront copy
 * (name, tagline, description, category, links) and the square icon. Before this
 * existed the only way to reprice a first-party app was to re-run
 * AppStoreCatalogSeeder or edit the row by hand in the database.
 *
 * Pricing caveat, surfaced in the UI: unlike add-on products, an app charges
 * `apps.price` LIVE at renewal (AppBillingService) — there is no per-install
 * quoted-price snapshot. Repricing therefore moves existing subscribers at their
 * next renewal. Every save freezes an AppVersion snapshot for the audit trail.
 */
class AppCatalogController extends Controller
{
    /** Billing models an app listing can use. */
    public const BILLING_TYPES = ['free', 'recurring', 'one_time', 'usage'];

    /** Common recurring periods, in days. Any integer is still accepted. */
    public const PERIOD_PRESETS = [
        7 => 'Weekly',
        30 => 'Monthly',
        90 => 'Quarterly',
        180 => 'Every 6 months',
        365 => 'Yearly',
    ];

    public function index(Request $request)
    {
        $status = (string) $request->query('status', '');
        $q = trim((string) $request->query('q', ''));

        $apps = App::with(['icon', 'developer'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                SearchTerm::apply($w, ['name', 'slug'], $q);
            }))
            ->orderBy('name')
            ->get();

        // One grouped count query rather than a per-row lookup in the view.
        $installs = AppInstallation::where('status', 'active')
            ->selectRaw('app_id, count(*) as total')
            ->groupBy('app_id')
            ->pluck('total', 'app_id');

        return view('admin.app_catalog.index', [
            'apps' => $apps,
            'installs' => $installs,
            'status' => $status,
            'q' => $q,
            'statuses' => ['draft', 'review', 'approved', 'rejected', 'delisted'],
        ]);
    }

    public function edit(int $id)
    {
        $app = App::with(['icon', 'screenshots', 'developer'])->findOrFail($id);

        return view('admin.app_catalog.edit', [
            'app' => $app,
            'activeInstalls' => AppInstallation::where('app_id', $app->id)->where('status', 'active')->count(),
            'billingTypes' => self::BILLING_TYPES,
            'periodPresets' => self::PERIOD_PRESETS,
            'categories' => (array) config('marketplace.app_categories', []),
            'grantableFeatures' => Feature::where('kind', 'boolean')
                ->where('platform_only', false)
                ->orderBy('group_')->orderBy('position')
                ->get(['key', 'label']),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $app = App::findOrFail($id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:40'],
            'status' => ['required', Rule::in(['draft', 'review', 'approved', 'rejected', 'delisted'])],
            'support_url' => ['nullable', 'url', 'max:255'],
            'docs_url' => ['nullable', 'url', 'max:255'],
            'billing_type' => ['required', Rule::in(self::BILLING_TYPES)],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'billing_period_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'usage_unit' => ['nullable', 'string', 'max:40'],
            'usage_price' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'grants_features' => ['nullable', 'array'],
            'grants_features.*' => ['string', 'max:64'],
        ]);

        // Normalise the commercials per billing model, so a switch from
        // recurring→free cannot leave a stale price that the renewal job charges.
        $data['price'] = (float) ($data['price'] ?? 0);
        $data['trial_days'] = (int) ($data['trial_days'] ?? 0);

        if ($data['billing_type'] === 'free') {
            $data['price'] = 0;
            $data['billing_period_days'] = null;
            $data['trial_days'] = 0;
            $data['usage_unit'] = null;
            $data['usage_price'] = null;
        } elseif ($data['billing_type'] === 'recurring') {
            $data['billing_period_days'] = (int) ($data['billing_period_days'] ?: 30);
            $data['usage_unit'] = null;
            $data['usage_price'] = null;
        } elseif ($data['billing_type'] === 'one_time') {
            $data['billing_period_days'] = null;
            $data['trial_days'] = 0;
            $data['usage_unit'] = null;
            $data['usage_price'] = null;
        } else { // usage
            $data['billing_period_days'] = (int) ($data['billing_period_days'] ?: 30);
            $data['trial_days'] = 0;
            $data['usage_price'] = (float) ($data['usage_price'] ?? 0);
        }

        // grants_features stays admin-only (D9): same guard as the review queue.
        $keys = array_values(array_filter((array) ($data['grants_features'] ?? [])));
        foreach ($keys as $key) {
            $feature = Feature::where('key', $key)->first();
            abort_if(! $feature || $feature->kind !== 'boolean' || $feature->platform_only, 422,
                ___('Feature key cannot be granted by an app: ').$key);
        }
        $grantsChanged = array_values((array) ($app->grants_features ?? [])) !== $keys;
        $data['grants_features'] = $keys ?: null;

        $priceChanged = (float) $app->price !== (float) $data['price']
            || $app->billing_type !== $data['billing_type']
            || (int) $app->billing_period_days !== (int) $data['billing_period_days'];

        $app->update($data);

        // Audit trail: the same snapshot the partner-side edit takes.
        if ($priceChanged || $grantsChanged) {
            AppVersion::freeze($app->fresh(), 'released');
        }

        // A grants change must reach live installs immediately, not at renewal.
        if ($grantsChanged) {
            $installer = app(AppInstallService::class);
            AppInstallation::where('app_id', $app->id)->where('status', 'active')->get()
                ->each(fn ($i) => $installer->syncFeatureGrants($i));
        }

        app_alert_success($priceChanged
            ? ___('App listing saved. Repricing applies to existing subscribers at their next renewal.')
            : ___('App listing saved.'));

        return redirect()->route('admin.app-catalog.edit', $app->id);
    }

    /** Upload / replace the single square listing icon (kind=icon, position 0). */
    public function uploadIcon(Request $request, int $id)
    {
        $app = App::findOrFail($id);
        $request->validate([
            'icon' => ['required', 'image', 'mimes:'.implode(',', config('marketplace.media.icon_mimes')), 'max:'.config('marketplace.media.max_kb')],
        ]);

        foreach ($app->media()->where('kind', 'icon')->get() as $old) {
            remove_file($old->disk_path);
            $old->delete();
        }

        $path = "storage/marketplace/app/{$app->id}/";
        $app->media()->create([
            'disk_path' => $path.image_upload($request->file('icon'), $path),
            'position' => 0,
            'kind' => 'icon',
        ]);

        app_alert_success(___('App icon updated.'));

        return redirect()->route('admin.app-catalog.edit', $app->id);
    }
}
