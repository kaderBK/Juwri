<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers;

use App\Services\SignupGuard;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Contracts\Content\PlatformSite;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Contracts\Tenancy\ResellerSite;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Mail\SupportMessage;
use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Http\Requests\ContactFormRequest;
use Juwri\Modules\Content\Models\Faq;
use Juwri\Modules\Content\Models\Page;
use Juwri\Modules\Content\Services\SitePages\SiteContext;
use Juwri\Modules\Content\Services\SitePages\SitePageRenderer;
use Juwri\Modules\Content\Services\SitePages\SitePublicResolver;
use Juwri\Modules\Marketing\Http\Requests\NewsletterRequest;
use Juwri\Modules\Marketing\Models\Subscriber;
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
        // the platform marketing site. Null falls through. Seam order is pinned by
        // SitePageSeamTest: reseller host > disable_landing_page > auth redirect >
        // site page > platform site > login.
        $sitePage = SitePublicResolver::homeFor(SiteContext::current());
        if ($sitePage) {
            return app(SitePageRenderer::class)->render($sitePage);
        }

        $platformHome = app(PlatformSite::class)->home();
        if ($platformHome !== null) {
            return $platformHome;
        }

        return redirect()->route('login');
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
        $tags = ['{SITE_TITLE}' => config('settings.site_title'), '{SITE_URL}' => route('home'), '{NAME}' => e((string) $senderName), '{EMAIL}' => e((string) $sender)]
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
