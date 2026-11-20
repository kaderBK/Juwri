<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Tables\Http\Controllers\User\Concerns;

use App\Models\StoreOption;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Tables\Services\Qr\QrRenderService;

/**
 * The store QR builder page, the server-side SVG renderer and the QR settings
 * save. Routes are unchanged — core/routes/web.php binds them by METHOD NAME
 * inside Route::controller('StoreController'), so a trait method resolves
 * identically to an inline one.
 */
trait BuildsQrCodes
{
    /** Store options the QR builder form may write (P6-67 allowlist). */
    private const QR_OPTION_KEYS = [
        'qr_fg_color', 'qr_bg_color', 'qr_padding', 'qr_radius', 'qr_source_label',
        'qr_mode', 'qr_mode_size', 'qr_position_x', 'qr_position_y', 'qr_text', 'qr_text_color',
    ];

    /**
     * The QR builder page. Opening it counts as onboarding step 5 (P0-7: the
     * checklist keys on real QR-builder engagement, not a menu preview).
     */
    public function qrBuilder(Store $store): View
    {
        abort_unless($this->ownsStore(request(), $store), 404);

        StoreOption::updateStoreOption($store->id, 'qr_builder_visited', '1');

        return view('owner.qr.builder', ['store' => $store, 'store_options' => store_options($store->id)]);
    }

    /**
     * Render the store QR as SVG server-side. The old client renderer depended
     * on a nova-theme jQuery plugin that no longer ships, so QR codes silently
     * stopped rendering everywhere.
     *
     * Query: fg/bg (#RRGGBB), padding (0-5), src (analytics label),
     * table (table key) or branch_id — the target URL is always built
     * server-side from the restaurant's own slug, never from client input.
     *
     * PARITY-RUN T10: the render itself now goes through QrRenderService like
     * every other QR surface, seeded with the store's saved QR design. The
     * legacy query parameters still WIN over the design, because the old QR
     * builder page and the branch-QR page pass them and must keep behaving
     * exactly as they did; `padding` maps onto the design's quiet zone.
     */
    public function qrSvg(Request $request, Store $store)
    {
        if ($store->user_id != $request->user()->id) {
            abort(404);
        }

        $options = store_options($store->id);

        if ($request->filled('branch_id')) {
            $url = reseller_store_url(route('publicView', $store->slug), $store).'?branch_id='.(int) $request->get('branch_id'); // R4b: reseller host
        } else {
            // Must be byte-identical to what the "Copy link" buttons copy.
            $url = store_public_qr_url(
                $store,
                $request->filled('table') ? (string) $request->get('table') : null,
                (string) $request->get('src', $options->qr_source_label ?? '')
            );
        }

        // Legacy overrides. Anything malformed is dropped by QrDesign::normalize()
        // rather than erroring — that "bad colour input still renders" contract is
        // pinned by MenuOperationsTest.
        $legacyFg = $request->get('fg', $options->qr_fg_color ?? null);
        $overrides = array_filter([
            'fg_color' => $legacyFg,
            // A legacy foreground means a FLAT two-colour code, the way this
            // endpoint has always rendered — so the corner colour follows it
            // instead of keeping the saved design's accent.
            'eye_color' => $legacyFg,
            'bg_color' => $request->get('bg', $options->qr_bg_color ?? null),
            'quiet_zone' => $request->has('padding') || isset($options->qr_padding)
                ? max(0, min(5, (int) $request->get('padding', $options->qr_padding ?? 2)))
                : null,
            'size' => 480,
        ], fn ($v) => $v !== null && $v !== '');

        $svg = app(QrRenderService::class)
            ->svg($url, store_qr_settings($store->id), $overrides);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * Save the QR builder form. Only the QR_OPTION_KEYS fields are stored as
     * store options of the same name (anything else posted is ignored); the
     * source label is slugged to at most 64 characters; an uploaded centre
     * logo (150px) replaces the previous one and is saved as `qr_image`.
     */
    public function qrBuilderSave(Request $request, Store $store): RedirectResponse
    {
        if (! $this->ownsStore($request, $store)) {
            return $this->refuseForm(___('Unexpected Error'));
        }
        $check = Validator::make($request->all(), [
            'qr_image' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'qr_source_label' => 'nullable|string|max:64',
            'qr_fg_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'qr_bg_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'qr_text_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'qr_padding' => 'nullable|integer|min:0|max:5',
            'qr_radius' => 'nullable|integer|min:0|max:50',
            'qr_mode' => 'nullable|integer|min:0|max:4',
            'qr_mode_size' => 'nullable|integer|min:0|max:100',
            'qr_position_x' => 'nullable|integer|min:0|max:100',
            'qr_position_y' => 'nullable|integer|min:0|max:100',
            'qr_text' => 'nullable|string|max:64',
        ]);
        if ($check->fails()) {
            return $this->refuseForm($this->validationSummary($check));
        }

        // P6-67: explicit allowlist — never mass-assign arbitrary option keys
        // (payment-gateway credentials, compliance flags) from the request.
        $options = $request->only(self::QR_OPTION_KEYS);
        if (array_key_exists('qr_source_label', $options)) {
            $options['qr_source_label'] = Str::of((string) $options['qr_source_label'])->trim()->slug('-')->substr(0, 64)->value();
        }
        if ($request->has('qr_image')) {
            $previous = store_options($store->id, 'qr_image');
            $options = ['qr_image' => image_upload($request->file('qr_image'), 'storage/store/logo/', '150x150', null, $previous)] + $options;
        }

        foreach ($options as $name => $value) {
            StoreOption::updateStoreOption($store->id, $name, $value);
        }
        app_alert_success(___('Saved Successfully'));

        return back();
    }
}
