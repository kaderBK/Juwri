<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers;

use App\Services\CountryDefs;
use App\Services\SignupGuard;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Contracts\Tenancy\ResellerSite;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Mail\SupportMessage;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Modules\Content\Http\Requests\ContactFormRequest;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\Faq;
use Juwri\Modules\Content\Models\Page;
use Juwri\Modules\Content\Models\Testimonial;
use Juwri\Modules\Content\Services\CompareDefs;
use Juwri\Modules\Content\Services\GuideDefs;
use Juwri\Modules\Content\Services\SitePages\SiteContext;
use Juwri\Modules\Content\Services\SitePages\SitePageRenderer;
use Juwri\Modules\Content\Services\SitePages\SitePublicResolver;
use Juwri\Modules\Content\Support\ContentLocales;
use Juwri\Modules\Marketing\Http\Requests\NewsletterRequest;
use Juwri\Modules\Marketing\Models\Subscriber;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Juwri\Modules\Tables\Services\Qr\QrRenderService;
use Juwri\Modules\Verticals\Services\VerticalLandingDefs;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class HomeController extends Controller
{
    public function __construct()
    {
        $this->activeTheme = active_theme();
    }

    /**
     * "/": a reseller host shows the reseller's landing; otherwise the platform
     * home (or a redirect, see below).
     */
    public function index(): mixed
    {
        // Reseller v2 P4 (4.5): on a reseller host with a published landing page,
        // render the reseller's OWN landing (their brand/plans/CTA) instead of the
        // platform marketing site — closes the worst brand hole ("/" leaks platform).
        // P1-13: the reseller's own site is the Reseller module's (ee) ResellerSite; Community has no resellers.
        $brandReseller = app(ResellerContext::class)->forRequest();
        if ($brandReseller && ! auth()->check()) {
            $resellerHome = app(ResellerSite::class)->home($brandReseller);
            if ($resellerHome !== null) {
                return $resellerHome;
            }
        }

        // The platform home: off → login; signed-in owners go to their dashboard.
        if (config('settings.disable_landing_page')) {
            return redirect()->route('login');
        }
        if (auth()->check()) {
            return redirect()->route('dashboard');
        }

        // HOME-PAGES HP-14: a published block-composed home (Site Pages) wins over
        // the legacy hardcoded Blade home. Null falls through unchanged — unpublish
        // is the per-site kill switch. Seam order is pinned by SitePageSeamTest:
        // reseller host > disable_landing_page > auth redirect > site page > legacy.
        $sitePage = SitePublicResolver::homeFor(SiteContext::current());
        if ($sitePage) {
            return app(SitePageRenderer::class)->render($sitePage);
        }

        // Optional marketing content — a missing table must not 500 the homepage.
        $optional = function (callable $read) {
            try {
                return $read();
            } catch (\Throwable $e) {
                return collect();
            }
        };

        /* WA-E2: the QuickOrder plugin's alternative marketing homepage is gone with
           the plugin (`store_order_homepage_enable` was 0 everywhere). */
        return view('marketing.home', $this->planListing() + [
            'testimonials' => $optional(fn () => Testimonial::query()->limit(10)->get()),
            'blogArticles' => $optional(fn () => Blog::query()->with('user')->where('status', 'publish')->limit(3)->get()),
            // Per-locale set with English fallback (see Faq::localized).
            'faqs' => $optional(fn () => Faq::localized()->limit(10)->get()),
        ]);
    }

    /** Platform pricing page; on a reseller host the reseller's own pricing page (R4/RP11), 404 when they turned it off. */
    public function pricing(): View
    {
        $reseller = active_brand_reseller();
        if ($reseller) {
            return app(ResellerSite::class)->pricing($reseller);
        }

        return view('marketing.pricing', $this->planListing());
    }

    /**
     * Public plan table data: active, publicly listed plans from cheapest to most
     * expensive (HPMS E14(c)), the sums of their prices per interval, and the
     * configured free / trial plans.
     *
     * @return array<string, mixed>
     */
    private function planListing(): array
    {
        $plans = app(PlanCatalog::class)->publicPlans();

        return [
            'plans' => $plans,
            'total_monthly' => $plans->sum('monthly_price'),
            'total_annual' => $plans->sum('annual_price'),
            'total_lifetime' => $plans->sum('lifetime_price'),
            'free_plan' => config('settings.free_membership_plan'),
            'trial_plan' => config('settings.trial_membership_plan'),
        ];
    }

    /** FAQ page (settings.enable_faqs), 20 per page. */
    public function faqs(): View
    {
        abort_if(! config('settings.enable_faqs'), 404);

        // Per-locale set with English fallback (see Faq::localized).
        $faqs = Faq::localized()->paginate(20);

        return view('marketing.faqs', compact('faqs'));
    }

    /**
     * Static marketing page: the full feature roster behind the home page's
     * "See all features" grid. No DB content, so no admin enable toggle.
     */
    public function features(): View
    {
        return view('marketing.features');
    }

    /**
     * Static marketing page (I1, SEO run): the theme gallery — every modern
     * storefront theme as an iPhone-framed live capture with a live-demo link.
     * No admin toggle; the only DB touch is a cheap existence probe so unseeded
     * environments degrade to a Theme-Store link instead of a 404.
     */
    public function themes(): View
    {
        // ThemeShowcaseSeeder publishes each demo storefront at "{slug}-demo";
        // probe which actually exist so the view only emits live-demo links that
        // resolve. Slugs come from the canonical keep-list (never a private copy
        // — see KeptTemplateRegistryTest); folio is display-only and never seeds
        // a demo store, so it naturally falls back to the Theme Store link.
        $demoStores = Store::whereIn('slug', array_map(static fn ($s) => $s.'-demo', KeptTemplates::MODERN))
            ->pluck('slug')->all();

        return view('marketing.themes', compact('demoStores'));
    }

    /**
     * Static marketing page (W1.1, SEO run): the WhatsApp-ordering money landing
     * page. No DB content, so no admin enable toggle.
     */
    public function whatsappLanding(): View
    {
        return view('marketing.whatsapp-ordering');
    }

    /**
     * Static marketing page (W1.2, SEO run): the zero-commission money landing
     * page. No DB content, so no admin enable toggle.
     */
    public function noCommissionLanding(): View
    {
        return view('marketing.no-commission');
    }

    /**
     * SEO: 301 a marketing page requested in a language it is not written in to
     * its best written language (ContentLocales). URL-prefix mode only — session
     * mode has a single URL per page, so there is nothing to consolidate.
     *
     * @param  list<string>  $locales
     */
    private function toWrittenLocale(array $locales): ?RedirectResponse
    {
        if (! config('settings.include_language_code')) {
            return null;
        }
        $current = get_lang();
        $target = ContentLocales::serveIn($locales, $current);
        if ($target === $current) {
            return null;
        }

        // Never redirect to ourselves: if the localized URL can't be built the
        // helper falls back to the current URL, which would loop.
        $url = ContentLocales::url($target);

        return $url === url()->current() ? null : redirect()->to($url, 301);
    }

    /**
     * SEO Growth W3.0 — country landing page (for/{country}). The cc whitelist
     * is the set of cite-checked defs files (App\Services\CountryDefs); an
     * un-authored country 404s. Content is the locale-appropriate block; the
     * demo-store link degrades to register when that showcase store isn't
     * seeded, so the page never emits a 404 link.
     */
    public function country(string $country): View|RedirectResponse
    {
        $def = CountryDefs::find($country);
        abort_if($def === null, 404);

        $locales = ContentLocales::of($def, ['cc', '_default']);
        if ($redirect = $this->toWrittenLocale($locales)) {
            return $redirect;
        }

        $c = CountryDefs::localize($def, get_lang());

        $demoUrl = route('register');
        $demoIsStore = false;
        $demoSlug = $c['demo_slug'] ?? ($def['demo_slug'] ?? null);
        if (is_string($demoSlug) && $demoSlug !== '' && Store::where('slug', $demoSlug)->exists()) {
            $demoUrl = url('/'.$demoSlug);
            $demoIsStore = true;
        }

        return view('marketing.country', [
            'def' => $def,
            'c' => $c,
            'cc' => $def['cc'],
            'demoUrl' => $demoUrl,
            'demoIsStore' => $demoIsStore,
            'pageHreflang' => ContentLocales::hreflang($locales),
            'pageCanonical' => ContentLocales::canonical(),
        ]);
    }

    /**
     * SEO Growth W4.0 — comparison-hub page. The slug whitelist is the set of
     * cite-checked competitor defs files (Juwri\Modules\Content\Services\CompareDefs); an unknown
     * slug 404s so no fabricated competitor is ever served.
     */
    public function compare(string $slug): View|RedirectResponse
    {
        $raw = CompareDefs::find($slug);
        abort_if($raw === null, 404);

        $locales = ContentLocales::of($raw);
        if ($redirect = $this->toWrittenLocale($locales)) {
            return $redirect;
        }

        // Resolve the locale-appropriate block (bilingual defs ship en + ar/tr…,
        // flat defs are single-locale) and apply field defaults.
        $cmp = CompareDefs::present($raw, get_lang());

        // Served only in the languages the comparison is written in (ContentLocales):
        // self canonical + hreflang limited to those languages.
        return view('marketing.compare', compact('cmp'))
            ->with('pageHreflang', ContentLocales::hreflang($locales))
            ->with('pageCanonical', ContentLocales::canonical());
    }

    /**
     * SEO Growth W5.2/W5.3 — long-form compliance guide (e-invoicing etc.).
     * Slug whitelist = Juwri\Modules\Content\Services\GuideDefs files; content is picked for the
     * request locale (bilingual guides ship en + ar, others fall back to en).
     */
    public function guide(string $slug): View|RedirectResponse
    {
        $guide = GuideDefs::find($slug);
        abort_if($guide === null, 404);

        $locales = ContentLocales::of($guide, ['country']);
        if ($redirect = $this->toWrittenLocale($locales)) {
            return $redirect;
        }

        $content = GuideDefs::localize($guide, get_lang());

        // Served only in the languages the guide is written in (ContentLocales):
        // self canonical + hreflang limited to those languages.
        return view('marketing.guide', compact('guide', 'content'))
            ->with('pageHreflang', ContentLocales::hreflang($locales))
            ->with('pageCanonical', ContentLocales::canonical());
    }

    /**
     * rev/mv-marketing — per-vertical (industry) landing page. Slug whitelist =
     * Juwri\Modules\Verticals\Services\VerticalLandingDefs files (retail, cosmetics, pharmacy, …);
     * an unknown slug 404s so no fabricated industry is ever served. When the
     * vertical's live demo store exists on this host, the "see it live" CTA
     * points at it (mirrors country()).
     */
    public function industry(string $slug): View
    {
        $def = VerticalLandingDefs::find($slug);
        abort_if($def === null, 404);

        $v = VerticalLandingDefs::localize($def, get_lang());

        $demoUrl = null;
        $demoIsStore = false;
        $demoSlug = $v['demo_slug'] ?? ($def['demo_slug'] ?? null);
        if (is_string($demoSlug) && $demoSlug !== '' && Store::where('slug', $demoSlug)->exists()) {
            $demoUrl = url('/'.$demoSlug);
            $demoIsStore = true;
        }

        return view('marketing.industry', [
            'def' => $def,
            'v' => $v,
            'slug' => $slug,
            'demoUrl' => $demoUrl,
            'demoIsStore' => $demoIsStore,
        ]);
    }

    /**
     * Free public tool: delivery-commission calculator (SEO run W2.1). Static
     * marketing page, no DB content and no auth — the market presets live in a
     * pure-data defs file the view requires. Rate-limited at the route.
     */
    public function commissionCalculator(): View
    {
        return view('marketing.tools.commission-calculator');
    }

    /**
     * Free public tool: QR-menu generator (SEO run W6.1). Client-side QR render,
     * no auth and no DB writes; rate-limited at the route.
     */
    public function qrMenuGenerator(): View
    {
        return view('marketing.tools.qr-menu-generator');
    }

    /**
     * Free public tool: AI menu-scanner teaser (SEO run W6.2). Teaser only — no
     * AI call and no server-side file storage; a canned before→after sample and
     * a CTA into the real AI Menu Studio.
     */
    public function menuScanner(): View
    {
        return view('marketing.tools.menu-scanner');
    }

    /**
     * Free public tool: reseller / white-label margin calculator. Pure
     * client-side math (wholesale seat cost vs. the reseller's own price across
     * N clients) — no auth, no DB writes; the funnel target is partners.index.
     */
    public function resellerCalculator(): View
    {
        return view('marketing.tools.reseller-calculator');
    }

    /**
     * W6.1 render endpoint: encode a visitor-supplied URL into a styled QR via
     * the ONE server-side renderer (QrRenderService — reuses bacon/bacon-qr-code,
     * no new dependency, no DB writes). The payload only drives the module
     * matrix; it is never written into the SVG markup, so an arbitrary URL is
     * safe to encode. Colours are hex-validated and QrDesign::normalize() drops
     * anything malformed. Rate-limited at the route.
     *
     * Query: url (required, ≤600 chars), fg/bg (#RRGGBB), format (svg|png),
     * download (attachment when truthy).
     */
    public function qrMenuGeneratorRender(Request $request, QrRenderService $qr): Response
    {
        $url = trim((string) $request->get('url', ''));
        // Keep the payload inside QR capacity and off the abuse radar; a bad
        // request returns 422 (never 5xx) so the route sweep stays green.
        if ($url === '' || mb_strlen($url) > 600) {
            return response(___('Enter a valid link to generate a QR code.'), 422, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $hex = fn ($v) => (is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v)) ? $v : null;
        $overrides = array_filter([
            'fg_color' => $hex($request->get('fg')),
            'eye_color' => $hex($request->get('fg')),
            'bg_color' => $hex($request->get('bg')),
            'size' => 512,
        ], fn ($v) => $v !== null);

        $format = $request->get('format') === 'png' ? 'png' : 'svg';

        try {
            if ($format === 'png') {
                $body = $qr->png($url, null, $overrides);
                if ($body === '') { // GD unavailable — fall back to SVG
                    $format = 'svg';
                    $body = $qr->svg($url, null, $overrides);
                }
            } else {
                $body = $qr->svg($url, null, $overrides);
            }
        } catch (\Throwable $e) {
            // Payload too dense for QR, etc. — never surface a 500 to a public tool.
            return response(___('That link is too long to fit in a QR code.'), 422, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $mime = $format === 'png' ? 'image/png' : 'image/svg+xml';
        $headers = ['Content-Type' => $mime, 'Cache-Control' => 'public, max-age=300', 'X-Content-Type-Options' => 'nosniff'];
        if ($format !== 'png') {
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }
        if ($request->boolean('download')) {
            $headers['Content-Disposition'] = 'attachment; filename="menu-qr-code.'.$format.'"';
        }

        return response($body, 200, $headers);
    }

    /** Testimonials page (settings.testimonials_enable), 21 per page. */
    public function testimonials(): View
    {
        abort_unless(config('settings.testimonials_enable'), 404);

        return view('marketing.testimonials', ['testimonials' => Testimonial::query()->paginate(21)]);
    }

    /**
     * CMS page by slug: active, in the current language or language-neutral;
     * members-only pages (type 1) 404 for guests.
     */
    public function page($slug): mixed
    {
        // RESELLER-PAGES R2: on a reseller domain, /page/{slug} serves the reseller's
        // own published block page (their brand), not the platform marketing page.
        // Upstream c623b24e3: a reseller host never falls back to platform pages
        // (ResellerPublicController::page 404s while reseller pages are off).
        $brandReseller = app(ResellerContext::class)->forRequest();
        if ($brandReseller) {
            return app(ResellerSite::class)->page(request(), (string) $slug);
        }

        // HOME-PAGES HP-15: a published block-composed site page for this slug wins
        // over the legacy Page CMS row; null falls through unchanged.
        $sitePage = SitePublicResolver::pageFor(SiteContext::current(), (string) $slug);
        if ($sitePage) {
            return app(SitePageRenderer::class)->render($sitePage);
        }

        $page = Page::query()
            ->where('slug', $slug)
            ->where('active', 1)
            ->where(fn ($q) => $q->where('translation_lang', get_lang())->orWhereNull('translation_lang'))
            ->firstOrFail();

        abort_if($page->type == 1 && ! auth()->check(), 404);

        // SEO (GSC "Duplicate, Google chose different canonical"): a CMS page is
        // keyed by slug; its real translations are the sibling rows carrying a
        // non-null translation_lang. Emit hreflang ONLY across those. A language-
        // neutral (translation_lang NULL) or single-locale page renders identical
        // content under every /{locale}/ prefix, so it consolidates to ONE
        // canonical (the default-locale URL) with no alternates instead of self-
        // canonicalising each prefix into a duplicate. Mirrors blog $blogHreflang
        // and the storefront store_canonical_url() scoping.
        [$pageHreflang, $pageCanonical] = $this->pageSeo($page);

        return view('marketing.page', compact('page', 'pageHreflang', 'pageCanonical'));
    }

    /**
     * hreflang set + canonical URL for a CMS /page/{slug}. Returns [$hreflang,
     * $canonical]:
     *  - a slug with >=2 real per-locale rows → the true translation set + self
     *    canonical (each locale is a genuine, distinct variant);
     *  - a language-neutral row (translation_lang NULL, served under EVERY prefix)
     *    → no alternates + a default-locale canonical, so the identical locale-
     *    prefix variants consolidate to one indexable page;
     *  - a single specific-locale page (reachable only at its own prefix, others
     *    404) → no alternates + self canonical (never point at a non-existent
     *    default-locale variant).
     * Mirrors blog $blogHreflang and the storefront store_canonical_url() scoping.
     *
     * @return array{string, string} [hreflangLinksHtml, canonicalUrl]
     */
    private function pageSeo(Page $page): array
    {
        $current = url()->current();

        // Session (non-prefix) mode: one URL per page, self-canonical, no alternates.
        if (! config('settings.include_language_code')) {
            return ['', $current];
        }

        $slug = (string) $page->slug;
        // Build every URL from the UNPREFIXED route, never from the current
        // request URL: a page crawled under a non-active locale prefix
        // (/hi/page/...) would make getLocalizedURL re-parse that prefix and throw
        // UnsupportedLocaleException. Guard each call so SEO annotation can never
        // 500 the page — a failed localization just falls back to self-canonical.
        $base = route('page', $slug);
        $localized = static function (string $code, string $url) {
            try {
                return LaravelLocalization::getLocalizedURL($code, $url, [], false) ?: null;
            } catch (\Throwable $e) {
                return null;
            }
        };

        $locales = Page::where('slug', $slug)
            ->where('active', 1)
            ->whereNotNull('translation_lang')
            ->pluck('translation_lang')
            ->map(fn ($c) => strtolower((string) $c))
            ->filter()
            ->unique()
            ->values();

        if ($locales->count() >= 2) {
            // Genuinely translated: advertise the real locale set, self-canonical.
            $tags = [];
            foreach ($locales as $code) {
                if ($url = $localized($code, $base)) {
                    $tags[] = '<link rel="alternate" hreflang="'.e($code).'" href="'.e($url).'" />';
                }
            }
            if ($default = $localized(LaravelLocalization::getDefaultLocale(), $base)) {
                $tags[] = '<link rel="alternate" hreflang="x-default" href="'.e($default).'" />';
            }

            return [implode("\n    ", $tags), $current];
        }

        // Language-neutral page (NULL row is served under every locale prefix):
        // consolidate the identical variants onto the default-locale URL. A single
        // specific-locale page only exists at its own prefix, so it self-canonicals.
        if ($page->translation_lang === null) {
            return ['', $localized(LaravelLocalization::getDefaultLocale(), $base) ?: $current];
        }

        return ['', $current];
    }

    public function contact(): View
    {
        // Upstream c623b24e3: a reseller's domain gets the reseller's own contact
        // page (messages go to its support email); none set = no contact page.
        if ($reseller = app(ResellerContext::class)->forRequest()) {
            return app(ResellerSite::class)->contact($reseller);
        }

        return view('marketing.contact');
    }

    /** Contact form → support mailbox (settings.email_sub_contact / email_message_contact). */
    public function contactSend(ContactFormRequest $request): RedirectResponse
    {
        if ($reseller = app(ResellerContext::class)->forRequest()) {
            return app(ResellerSite::class)->contactSend($request, $reseller);
        }

        return $this->sendSupportMessage($request, 'contact', [
            '{CONTACT_SUBJECT}' => e((string) $request->input('subject')),
        ], ___('Thank you for contacting us.'));
    }

    public function feedback(): View
    {
        return view('marketing.feedback');
    }

    /** Feedback form → support mailbox (settings.email_sub_feedback / email_message_feedback). */
    public function feedbackSend(ContactFormRequest $request): RedirectResponse
    {
        return $this->sendSupportMessage($request, 'feedback', [
            '{PHONE}' => e((string) $request->input('phone')),
            '{FEEDBACK_SUBJECT}' => e((string) $request->input('subject')),
        ], ___('Thank you for your feedback.'));
    }

    /**
     * Queue a SupportMessage to settings.contact_email built from the
     * `email_sub_<kind>` / `email_message_<kind>` templates, replying to the
     * sender. Does nothing (with a notice) when no contact address is set.
     *
     * @param  array<string, mixed>  $extraTags  tags placed between {EMAIL} and {MESSAGE}
     */
    private function sendSupportMessage(ContactFormRequest $request, string $kind, array $extraTags, string $thanks): RedirectResponse
    {
        $mailbox = config('settings.contact_email');
        if (! $mailbox) {
            app_alert_error(___('Email sending is disabled.'));

            return back();
        }

        // Abuse Guard P4: honeypot + Turnstile server check on this public form.
        SignupGuard::assertAllowed($request, (string) $request->email);

        $sender = $request->input('email');
        $senderName = $request->input('name');
        // Visitor-supplied fields are escaped: the mail body is HTML (upstream
        // c623b24e3). $extraTags arrive escaped from the callers.
        $tags = ['{SITE_TITLE}' => config('settings.site_title'), '{SITE_URL}' => route('home'), '{NAME}' => e((string) $senderName), '{EMAIL}' => $sender]
            + $extraTags
            + ['{MESSAGE}' => nl2br(e((string) $request->input('message')))];

        try {
            $mail = new SupportMessage(config("settings.email_sub_{$kind}"), config("settings.email_message_{$kind}"), $tags, $sender, $senderName);
            Mail::to($mailbox)->queue($mail->afterCommit());
        } catch (\Exception $e) {
            app_alert_error(___('Email sending failed, please try again.'));

            return back();
        }

        app_alert_success($thanks);

        return back();
    }

    /** Newsletter sign-up (JSON). */
    public function newsletter(NewsletterRequest $request): JsonResponse
    {
        Subscriber::create(['email' => $request->input('email'), 'joined' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    /** Session-mode language switch: remember the language (it must exist) and go back. */
    public function localize($code): RedirectResponse
    {
        $locale = Language::query()->where('code', $code)->firstOrFail()->code;
        app()->setLocale($locale);
        session()->put('locale', $locale);

        return redirect()->back();
    }

    /**
     * rev/seo R3: legacy /lang/{code} URLs (old sitemap entries, external links)
     * in URL-prefix locale mode. Permanent redirect to the localized home —
     * deliberately NOT referer-based: 301s are cached per-URL by browsers, so a
     * referer-dependent target would serve stale destinations. In-app language
     * switching uses lang_url() and never hits this route in prefix mode.
     */
    public function localizeRedirect($code)
    {
        Language::where('code', $code)->where('active', 1)->firstOrFail();

        return redirect()->to(
            LaravelLocalization::getLocalizedURL($code, url('/'), [], false),
            301
        );
    }
}
