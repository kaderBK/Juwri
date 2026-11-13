<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Http\Controllers\User;

use App\Models\Customer;
use App\Services\FeatureAccess;
use App\Support\BioAppearance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\ExternalRedirect;
use Juwri\Core\Support\Html;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Juwri\Modules\Biolink\Support\BioClickContext;

/**
 * BIOLINK-RUN A4: the public bio page. SPEC §4.
 *
 * Resolves the store by slug, gates on FeatureAccess::storeAllows + the store's
 * own enable switch (302 → the menu when off/dark — NEVER 404 a live slug),
 * resolves branch + preset (G3 entitlement fallback), classifies the traffic
 * source (G5), records the page view as a bio_clicks row at block_id=0 (G1 —
 * never StoreView), then renders the preset family view. A signed `?preview=`
 * (owner/staff dashboard live preview) bypasses the enable+kill checks and the
 * view recording.
 *
 * The public route carries NO `feature:` middleware (storefront gate is asked
 * in-controller via storeAllows — the three-times mistake this codebase made).
 */
class BioPublicController extends Controller
{
    /** Referrer hosts we bucket as 'social'. */
    private const SOCIAL_HOSTS = [
        'instagram', 'l.instagram', 'tiktok', 'facebook', 'fb.', 'm.facebook',
        'twitter', 'x.com', 't.co', 'snapchat', 'youtube', 'youtu.be',
        'linkedin', 'pinterest', 'threads', 'wa.me', 'whatsapp',
    ];

    public function show(Request $request, string $slug, BioLinkService $svc): \Symfony\Component\HttpFoundation\Response
    {
        $store = Store::where('slug', $slug)->first();
        if (! $store) {
            abort(404); // an unknown slug genuinely 404s; a LIVE slug never does
        }

        // rev/external-redirect A3: the bio page has its own independent opt-out
        // (`bio_external_redirect_*`), resolved ABOVE the biolink gate so an
        // opted-out store's bio redirects even when the biolink feature is off.
        // Same inline `status == 1` H1 guard as the menu hook (a banned store goes
        // dark, not redirected); bio is ALWAYS an instant 302 (no interstitial).
        // Owner/admin preview bypasses inside the resolver.
        if ($store->user && $store->user->status == 1
            && ($bioExtRedirect = ExternalRedirect::resolveBio($store, $request)) !== null) {
            return $bioExtRedirect;
        }

        $settings = $svc->resolveSettings($store->id);
        $preview = $this->isValidPreview($request);
        $ownerStaff = $this->viewerOwnsStore($store);

        // Gate: feature on (kill-switch + plan) AND the store's own enable switch,
        // else 302 to the menu. Preview bypasses both (dashboard live preview).
        if (! $preview) {
            if (! FeatureAccess::storeAllows($store->id, 'biolink') || ! $settings['enabled']) {
                return redirect()->to($this->menuUrl($store));
            }
        }

        $branchId = $this->resolveBranch($request, $store);

        // G3: a stored preset the plan no longer allows (downgrade / tier flip)
        // falls back to classic_flat at render.
        $preset = (string) ($settings['preset'] ?: 'classic_flat');
        if (! $svc->presetAllowed($store->id, $preset)) {
            $preset = 'classic_flat';
        }

        // Live design preview: a signed dashboard preview may override the skin via
        // ?preview_preset= so the owner can experience any design over their real
        // blocks before applying it. Only honored in preview mode and only for a
        // plan-allowed preset (never changes what a guest sees).
        if ($preview) {
            $pv = preg_replace('/[^a-z0-9_]/', '', (string) $request->query('preview_preset', ''));
            if (is_string($pv) && $pv !== '' && $svc->presetAllowed($store->id, $pv)) {
                $preset = $pv;
            }
        }

        // G5: classify the source ONCE on the initial GET and thread it into the
        // view so the click beacon echoes the same value (the beacon's own
        // referrer is the bio page).
        $source = $this->classifySource($request);
        $device = $this->classifyDevice($request);

        // G1: record the page VIEW as a bio_clicks upsert at block_id=0. Skip for
        // a signed preview and for the logged-in owner/staff (their own eyeballs
        // must not inflate a store's insights).
        if (! $preview && ! $ownerStaff) {
            // bio-t15 F0 (Lane D wire): source/device keep the existing G5 classification;
            // BioClickContext adds the coarse, PII-free country + host-only referrer buckets.
            $ctx = BioClickContext::capture($request);
            $storeId = $store->id;
            defer(fn () => $svc->recordView($storeId, $branchId, $source, $device, $ctx->country, $ctx->ref));
        }

        // Advanced HTML (Builder.js) mode: render the sanitized published snapshot
        // inside the minimal bio chrome instead of the preset/block layouts. A signed
        // preview shows the DRAFT (bio_html). Output-time XSS choke point: the HTML is
        // re-sanitized through Html::cleanPage() at echo (idempotent — already cleaned
        // on save/publish). This route runs under the ENFORCING storefront CSP
        // (AddCspHeaders, NO unsafe-eval), which is correct: the published page is
        // plain allowlist-sanitized HTML with no Builder.js/eval at runtime.
        if (($store->bio_builder ?? 'blocks') === 'html') {
            $rawHtml = $preview ? (string) $store->bio_html : (string) $store->bio_published_html;

            // A5 parity: mark the bio page as seen this session so the bio_first
            // interstitial fires only on the first menu load (same as the block path).
            $request->session()->put('bio_seen_'.$store->id, true);

            return response()->view('bio.html', [
                'store' => $store,
                'html' => Html::cleanPage($rawHtml),
                'rtl' => is_rtl(),
                'isPreview' => $preview,
                'poweredBy' => $this->poweredByFor($store),
                'favicon' => (string) (brand_favicon() ?? ''),
            ]);
        }

        $presetData = $this->loadPreset($preset);
        $family = (string) ($presetData['family'] ?? 'list');
        // D3: pass $source so outbound links get utm_medium=<source> (public render only).
        $blocks = $svc->blocksFor($store->id, $branchId, false, $source);

        // bio-t15 Lane B (SPEC §3/§8): appearance is a raw, owner-controlled JSON
        // sub-tree of bio_settings (deep-merged in by the existing settings.save —
        // never validated on write). BioAppearance::sanitize()/toTokens() is the
        // ONLY gate: everything here is untrusted until it passes through there.
        // $planFlags mirrors the SAME FeatureAccess::storeAllows() call the
        // 'biolink' master gate above already uses (line ~56) — see the B9
        // migration docblock for why a missing features-table row would fail
        // this OPEN.
        $appearance = is_array(data_get($settings, 'appearance')) ? data_get($settings, 'appearance') : [];
        $planFlags = [
            'bio_pro' => FeatureAccess::storeAllows($store->id, 'bio_pro'),
            'bio_analytics_pro' => FeatureAccess::storeAllows($store->id, 'bio_analytics_pro'),
        ];

        $presetData['fonts'] = array_merge(
            $presetData['fonts'] ?? [],
            BioAppearance::selectedFonts($appearance, $presetData['fonts'] ?? [], $planFlags)
        );

        $profile = $this->bioProfile($store, $settings, $planFlags);

        $contract = [
            'store' => $store,
            'branch' => $branchId ? Branch::find($branchId) : null,
            'preset' => $presetData,
            'tokens' => array_merge($presetData['tokens'] ?? [], BioAppearance::toTokens($appearance, $planFlags)),
            'bgImageStyle' => BioAppearance::backgroundImageStyle($appearance, $store->id, $planFlags),
            'blocks' => $blocks,
            'bioSettings' => $settings,
            'profile' => $profile,
            'poweredBy' => $this->poweredByFor($store),
            'rtl' => is_rtl(),
            'source' => $source,
            'og' => $this->ogMetaFor($store, $appearance, $profile),
            'favicon' => $profile['avatar'] ?: (string) (brand_favicon() ?? ''),
        ];

        // A5: mark this session as having seen the bio page so the bio_first
        // interstitial fires only on the FIRST menu load.
        $request->session()->put('bio_seen_'.$store->id, true);

        $view = 'bio.layouts.'.$family;
        if (View::exists($view)) {
            return response()->view($view, $contract);
        }

        // Lane C (bio/layouts/*) not merged into this tree yet: a minimal,
        // functional, CSP-clean fallback so the route works and is testable. This
        // shim is self-removing — once the preset family views exist it is never
        // reached (F0 merges Lane C).
        return response($this->fallbackHtml($contract));
    }

    /**
     * BIOLINK-RUN A6: the public click beacon. Records a tap on one bio_block as a
     * bio_clicks row + a clicks_cached bump (G8). Source is echoed from the page
     * (G5 — the beacon's own referrer is the bio page, so we trust the stamped
     * value over the referrer). Always 204s (a beacon has no UI). No-ops silently
     * for a dark store, an owner/staff self-view, or a block that is not this
     * store's — never leaks whether a block exists.
     */
    public function click(Request $request, string $slug, int $block, BioLinkService $svc): Response
    {
        $store = Store::where('slug', $slug)->first();
        if (! $store) {
            return response()->noContent(); // 204: never 404 a beacon
        }

        $record = FeatureAccess::storeAllows($store->id, 'biolink')
            && ! $this->viewerOwnsStore($store)
            && BioBlock::where('id', $block)->where('store_id', $store->id)->exists();

        if ($record) {
            $source = $this->beaconSource($request);
            $device = $this->classifyDevice($request);
            $ctx = BioClickContext::capture($request);
            $svc->recordClick($store->id, $block, $source, $device, $ctx->country, $ctx->ref);
        }

        return response()->noContent();
    }

    /** Source for a click beacon: the value the page stamped, echoed in the POST. */
    private function beaconSource(Request $request): string
    {
        $src = strtolower((string) $request->input('src', ''));
        if (in_array($src, ['qr', 'social', 'direct', 'share', 'other'], true)) {
            return $src;
        }

        return $this->classifySource($request);
    }

    /**
     * BIOLINK-RUN A8: email/phone capture → the store's existing Customer
     * marketing rail (the SAME model {slug}/unsubscribe manages). Honeypot +
     * ratelimit (route). Consent is implied by the submit (the consent line is
     * rendered on the page); we dedup on (store_id, email|phone) exactly as
     * the checkout lead-capture does, and tag `source = 'bio'`.
     */
    public function subscribe(Request $request, string $slug): JsonResponse|RedirectResponse
    {
        $store = Store::where('slug', $slug)->first();
        if (! $store || ! FeatureAccess::storeAllows($store->id, 'biolink')) {
            return $this->captureResponse($request, false);
        }

        // Honeypot: a real visitor never fills `company_website` (the hidden field
        // the capture form actually renders — gap-fix: this used to read `company`,
        // a name no form emits, so the check was dead). A bot that filled it gets a
        // success response and nothing is written.
        if (trim((string) $request->input('company_website', '')) !== '') {
            return $this->captureResponse($request, true);
        }

        $email = filter_var((string) $request->input('email', ''), FILTER_VALIDATE_EMAIL) ?: null;
        $phone = trim((string) $request->input('phone', ''));
        $phone = $phone !== '' ? strip_tags($phone) : null;
        $name = strip_tags((string) $request->input('name', ''));

        if (! $email && ! $phone) {
            return $this->captureResponse($request, false, ___('Please enter an email or phone number.'));
        }

        $lookup = null;
        if ($email) {
            $lookup = Customer::where('store_id', $store->id)->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $email))])->first();
        }
        if (! $lookup && $phone) {
            $lookup = Customer::where('store_id', $store->id)->where('phone', $phone)->first();
        }

        $data = [
            'store_id' => $store->id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'source' => 'bio',
            'marketing_consent' => true,
            'consent_at' => now(),
        ];
        if ($phone) {
            $data['sms_consent'] = true;
        }

        if ($lookup) {
            $lookup->fill(array_filter($data, fn ($v) => $v !== null && $v !== ''))->save();
        } else {
            Customer::create($data);
        }

        return $this->captureResponse($request, true, ___('Thanks — you are on the list!'));
    }

    /** Shared response for the capture endpoint (JSON for fetch, redirect-back for a form). */
    private function captureResponse(Request $request, bool $ok, ?string $message = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
        }

        return back()->with($ok ? 'bio_capture_ok' : 'bio_capture_error', $message);
    }

    /** A signed preview URL (owner/staff dashboard). Capability = the signature. */
    private function isValidPreview(Request $request): bool
    {
        // `preview_preset` (the live design switcher's skin override) is appended to
        // the signed URL client-side, so it must be ignored when validating the
        // signature — otherwise the extra param would break the preview.
        return $request->has('preview')
            && $request->hasValidSignatureWhileIgnoring(['preview_preset']);
    }

    /** Is the logged-in user the store owner (or their staff)? Skips view-recording. */
    private function viewerOwnsStore(Store $store): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if ((int) $store->user_id === (int) $user->id) {
            return true;
        }

        // Staff resolve to their owner via the dashboard helper when available.
        if (function_exists('dashboard_owner') && ($owner = dashboard_owner())) {
            return (int) $store->user_id === (int) $owner->id;
        }

        return false;
    }

    /** Localized menu URL for this store (302 target when bio is off). */
    private function menuUrl(Store $store): string
    {
        return storefront_localized_link(route('publicView', $store->slug)) ?? url('/'.$store->slug);
    }

    /**
     * BIOLINK-TPL T3-3: the powered-by string for the bio footer, or '' to hide it.
     * Composes the reseller white-label case (brand_show_powered_by() → false when a
     * reseller/agency removes the platform line) with the MERCHANT plan feature
     * (store_plan_allows hide_branding — the same gate the storefront themes use), so
     * EITHER path hides the footer. Never regresses the reseller case.
     */
    private function poweredByFor(Store $store): string
    {
        $show = brand_show_powered_by() && ! store_plan_allows($store, 'hide_branding');

        return $show ? brand_context()->name() : '';
    }

    /**
     * Build the header profile (name, tagline, avatar, cover, …) HERE, in the
     * controller, so it reaches the family header partials as view data. Building it
     * in the layout's @php did not work: Blade renders the family @section('page')
     * eagerly in the child view's scope, before the layout's @php runs, so the header
     * partials saw an undefined $profile and always showed the "◈" glyph.
     *
     * Fallbacks are empty-string aware (the bio settings default their fields to ''
     * not null, so `??` would skip the store's real data) and skip the 'default.png'
     * platform "no image" sentinel, so a store with no bio-specific title/avatar still
     * shows its store name, logo and cover.
     *
     * bio-t15 Lane B B5 (SPEC §3 header schema): `appearance.hide.*` are folded
     * into the OUTPUT VALUES here (tagline/status/verified become '' /false when
     * hidden, title keeps the existing `hide_title` flag both partials already
     * check) rather than as new output keys — every family header partial
     * (`_profile.blade.php` etc.) already does `@if (!empty($p['tagline']))` etc.,
     * so this makes the hide toggles work with ZERO template changes, which
     * matters because those partials sit outside this lane's file fence.
     * `appearance.cover_image`/`show_cover` layer an explicit owner-picked cover
     * AHEAD of the legacy cover_url/post-field chain (never regressing a store
     * that never touched the appearance tab: absent appearance.cover_image falls
     * straight through to the pre-existing resolution, unaffected by show_cover).
     *
     * @param  array<string,mixed>  $settings
     * @param  array<string,bool>  $planFlags
     * @return array<string,mixed>
     */
    private function bioProfile(Store $store, array $settings, array $planFlags = []): array
    {
        $storeId = (int) $store->id;
        $resolveMedia = static function (?string $raw, string $dir) use ($storeId): string {
            $raw = trim((string) $raw);
            if ($raw === '') {
                return '';
            }

            return Str::startsWith($raw, ['http://', 'https://', '/']) ? $raw : media_url($dir, $raw, $storeId);
        };
        $firstText = static function (...$vals): string {
            foreach ($vals as $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    return $v;
                }
            }

            return '';
        };
        $firstImage = static function (string $dir, ...$raws) use ($resolveMedia): string {
            foreach ($raws as $raw) {
                $raw = trim((string) $raw);
                if ($raw !== '' && $raw !== 'default.png') {
                    return $resolveMedia($raw, $dir);
                }
            }

            return '';
        };

        $appearance = is_array(data_get($settings, 'appearance')) ? data_get($settings, 'appearance') : [];
        $a = BioAppearance::sanitize($appearance, $planFlags);
        $hide = $a['hide'] ?? [];

        // appearance.display_name is the owner's explicit override for the visible
        // heading (.bio-name); it wins over the legacy store-name fallback chain.
        $name = $firstText($a['display_name'] ?? '', $settings['title'] ?? '', data_get($store, 'title'), data_get($store, 'name'));

        // cover_image is a bare library filename the media picker supplied; the
        // library stores files under storage/menu (MediaAsset::url), so resolve
        // it there for THIS store.
        $appearanceCover = ! empty($a['cover_image'])
            ? $resolveMedia((string) $a['cover_image'], 'storage/menu/')
            : '';
        $legacyCover = $firstText($settings['cover_url'] ?? '')
            ?: $firstImage('storage/store/cover/', data_get($store, 'cover_image'));
        // show_cover is only a gate on the NEW appearance.cover_image (default OFF
        // there per SPEC §3); the legacy chain keeps its pre-existing unconditional
        // behavior so an already-live store never goes blank from an untouched tab.
        $cover = $appearanceCover !== '' ? (($a['show_cover'] ?? false) ? $appearanceCover : '') : $legacyCover;

        return [
            'name' => $name,
            'hide_title' => ! empty($settings['hide_title'] ?? false) || ! empty($hide['title']),
            'tagline' => ! empty($hide['tagline']) ? '' : $firstText($a['tagline'] ?? '', $settings['tagline'] ?? ''),
            'sub' => trim((string) ($settings['subtitle'] ?? '')),
            'status' => ! empty($hide['status']) ? '' : trim((string) ($settings['status_label'] ?? '')),
            'verified' => ! empty($hide['verified']) ? false : ! empty($settings['verified'] ?? false),
            // appearance.avatar_image (the picked profile picture) wins; else the
            // legacy avatar_url, else the store logo. The picked file is a bare
            // library filename under storage/menu (MediaAsset::url).
            'avatar' => $resolveMedia((string) ($a['avatar_image'] ?? ''), 'storage/menu/')
                ?: ($firstText($settings['avatar_url'] ?? '')
                    ?: $firstImage('storage/store/logo/', data_get($store, 'main_image'))),
            'cover' => $cover,
            'initial' => mb_strtoupper(mb_substr(ltrim($name, '@'), 0, 1)) ?: '◈',
        ];
    }

    /**
     * bio-t15 Lane B B6 (SPEC §6): og.title||site_title||post title,
     * og.description, og:image (og.image → cover → logo → platform default).
     * Takes the ALREADY-RESOLVED $profile (from bioProfile(), same request) so
     * the header name/tagline/cover fallback chain is derived exactly once.
     *
     * @param  array<string,mixed>  $appearance
     * @param  array<string,mixed>  $profile
     * @return array{title:string,description:string,image:string}
     */
    private function ogMetaFor(Store $store, array $appearance, array $profile): array
    {
        $storeId = (int) $store->id;

        $fallbackTitle = trim((string) ($profile['name'] ?: data_get($store, 'title') ?: brand_title()));
        $fallbackDescription = trim((string) ($profile['tagline'] ?: ''));
        $fallbackImage = $profile['cover'] ?: $profile['avatar'] ?: (string) (brand_logo() ?? '');

        $meta = BioAppearance::ogMeta($appearance, $fallbackTitle, $fallbackDescription, $fallbackImage);

        if (! empty($meta['image']) && ! Str::startsWith($meta['image'], ['http://', 'https://', '/'])) {
            $meta['image'] = media_url('storage/menu/', $meta['image'], $storeId);
        }

        return $meta;
    }

    /** `?branch=ID` must be a child branch of this store, else null (= main page). */
    private function resolveBranch(Request $request, Store $store): ?int
    {
        $branchId = $request->query('branch');
        if (! $branchId || ! ctype_digit((string) $branchId)) {
            return null;
        }

        $ok = Branch::where('id', (int) $branchId)->where('store_id', $store->id)->exists();

        return $ok ? (int) $branchId : null;
    }

    /**
     * G5: classify traffic source from the initial request — explicit `?src`,
     * any `?qr-id` (a QR scan), else the referrer host → social/direct.
     */
    private function classifySource(Request $request): string
    {
        $src = strtolower((string) $request->query('src', ''));
        if (in_array($src, ['qr', 'social', 'direct', 'share', 'other'], true)) {
            return $src;
        }

        if ($request->query('qr-id') !== null) {
            return 'qr';
        }

        $ref = (string) $request->headers->get('referer', '');
        if ($ref === '') {
            return 'direct';
        }

        $host = strtolower((string) parse_url($ref, PHP_URL_HOST));
        if ($host === '') {
            return 'direct';
        }

        foreach (self::SOCIAL_HOSTS as $needle) {
            if (str_contains($host, $needle)) {
                return 'social';
            }
        }

        // A referrer from our own host (e.g. the menu → bio) is direct; any other
        // external site is 'other'.
        return str_contains($host, (string) parse_url(config('app.url'), PHP_URL_HOST)) ? 'direct' : 'other';
    }

    /** Coarse device bucket from the User-Agent. */
    private function classifyDevice(Request $request): string
    {
        $ua = strtolower((string) $request->userAgent());
        if ($ua === '') {
            return 'other';
        }
        if (str_contains($ua, 'ipad') || (str_contains($ua, 'tablet') && ! str_contains($ua, 'mobile'))) {
            return 'tablet';
        }
        if (str_contains($ua, 'mobi') || str_contains($ua, 'iphone') || str_contains($ua, 'android')) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * Load a preset json from Lane C's `bio/presets/{slug}.json`. Falls back to a
     * built-in classic_flat token set when the file is not present yet (pre-C),
     * so the route is always renderable.
     *
     * @return array<string,mixed>
     */
    private function loadPreset(string $slug): array
    {
        $path = resource_path('views/bio/presets/'.$slug.'.json');
        if (is_file($path)) {
            $json = json_decode((string) file_get_contents($path), true);
            if (is_array($json)) {
                return $json;
            }
        }

        return [
            'slug' => 'classic_flat',
            'name' => 'Classic Flat',
            'family' => 'list',
            'tier' => 'basic',
            'rtl_native' => false,
            'fonts' => [],
            'tokens' => [
                '--bg' => '#9cd6c8', '--card' => '#ffffff', '--ink' => '#123c32',
                '--muted' => '#2c5a4e', '--accent' => '#0f7d5a', '--radius' => '999px',
                '--shadow' => '0 2px 8px rgba(20,60,50,.12)', '--btn-style' => 'fill',
            ],
        ];
    }

    /**
     * Minimal fallback markup (pre-Lane-C). No inline <script>/<style>; block data
     * is exposed via data-* attributes so the engine tests can assert on it.
     *
     * @param  array<string,mixed>  $contract
     */
    private function fallbackHtml(array $contract): string
    {
        $store = $contract['store'];
        $preset = e((string) ($contract['preset']['slug'] ?? 'classic_flat'));
        $dir = $contract['rtl'] ? 'rtl' : 'ltr';
        $count = count($contract['blocks']);

        $items = '';
        foreach ($contract['blocks'] as $b) {
            $label = e((string) ($b['label'] ?? ''));
            $url = e((string) ($b['url'] ?? '#'));
            $items .= '<li><a href="'.$url.'" data-bio-block="'.(int) $b['id'].'" data-bio-type="'.e((string) $b['type']).'">'.$label.'</a></li>';
        }

        return '<!doctype html><html lang="'.e(app()->getLocale()).'" dir="'.$dir.'">'
            .'<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.e((string) $store->title).'</title></head>'
            .'<body data-bio-preset="'.$preset.'" data-bio-source="'.e((string) $contract['source']).'" data-bio-blocks="'.$count.'">'
            .'<main><h1>'.e((string) $store->title).'</h1>'
            .'<ul>'.$items.'</ul></main></body></html>';
    }
}
