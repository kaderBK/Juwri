<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Legal;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\File;
use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Legal\CookieConsent;
use Juwri\Core\Legal\CookieRegistry;
use Juwri\Core\Legal\LegalDocuments;
use Juwri\Core\Models\Language;
use Juwri\Core\Rules\ReservedSlug;
use Juwri\Modules\Marketing\Http\Middleware\CaptureGuestReferral;
use Tests\TestCase;

/**
 * P1-23 follow-up (privacy.md §5, FILL-LIST item 16): /cookies lists every cookie the app can set — name, purpose,
 * category, lifetime, first / third party — from ONE registry (CookieRegistry) that the banner and the server consent
 * gate read too. The page shows on every install; JUWRI LTD's sentence only with LEGAL_PAGES. A source scan fails on
 * any cookie the code sets that the registry does not list (and on a listed first-party cookie nothing sets).
 */
class CookieListTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];

    /** Values that are correctly the same as English in that language. */
    private const SAME_AS_ENGLISH = [
        'de' => ['title', 'category.marketing'], 'es' => ['title', 'category.marketing'],
        'fr' => ['title', 'category.marketing'], 'pt' => ['title', 'category.marketing'],
    ];

    /** Ways the code sets a cookie: [regex, group] — PHP, Blade and the storefront / dashboard JavaScript. */
    private const SETTERS = [
        '~\.cookie\s*=\s*[\'"]([\w-]+)=~',                              // document.cookie = 'name=…'
        '~\bset[Cc]ookie\(\s*[\'"]([\w-]+)[\'"]~',                       // setCookie('name', …) / setcookie('name', …)
        '~Cookie::(?:make|forever|queue)\(\s*[\'"]([\w-]+)[\'"]~',      // Cookie::make('name', …)
        '~(?<![\w$>])cookie\(\s*[\'"]([\w-]+)[\'"]\s*,~',                 // cookie('name', value, …)
        '~->cookie\(\s*[\'"]([\w-]+)[\'"]\s*,~',                         // $response->cookie('name', …)
        '~COOKIE\s*=\s*[\'"]([\w-]+)[\'"]~',                             // const …COOKIE = 'name'
        '~rememberAttribution\(\s*\$\w+,\s*[\'"]([\w-]+)[\'"]~',         // CookieConsent::rememberAttribution($r, 'name', …)
    ];

    /**
     * Listed first-party cookies the scan cannot see: framework-set (session, CSRF, remember-me) and names built at run
     * time (the reseller announcement cookie rsl_ann_<user id>, CustomerAnnouncements::cookieName()).
     */
    private const NOT_IN_OUR_CODE = ['session', 'xsrf', 'remember', 'reseller_announcements'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        foreach (['en' => ['English', 'ltr'], 'ar' => ['Arabic', 'rtl']] as $code => [$name, $dir]) {
            Language::updateOrCreate(['code' => $code], ['name' => $name, 'direction' => $dir, 'active' => 1]);
        }
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['legal.enabled' => false]);
    }

    public function test_every_install_lists_every_registry_cookie_without_juwri_company_text(): void
    {
        $html = $this->get('/cookies')->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="mk-h1">'.e(__('cookies.title')).'</h1>', $html);
        foreach (CookieRegistry::all() as $key => $cookie) {
            $this->assertMatchesRegularExpression(
                '~<tr data-cookie="'.preg_quote($key, '~').'">\s*<td><code dir="ltr">'.preg_quote(e($cookie['name']), '~').'</code></td>\s*<td>'
                .preg_quote(e(__('cookies.purpose.'.$cookie['purpose'])), '~').'</td>\s*<td>'.preg_quote(e(CookieRegistry::lifetime($cookie['minutes'])), '~').'</td>~',
                $html,
                $key
            );
        }
        foreach (array_keys(CookieRegistry::byCategory()) as $category) {
            $this->assertStringContainsString('data-cookie-category="'.$category.'"', $html);
        }
        $this->assertStringContainsString('<code dir="ltr">'.e(config('session.cookie')).'</code>', $html, 'the real session cookie name');
        $this->assertStringContainsString(e(__('cookies.party.third', ['provider' => 'Google'])), $html);
        $this->assertStringContainsString('data-cookie-settings', $html, 're-open the banner from the list');
        $this->assertStringNotContainsString('data-cookie-company', $html);
        $this->assertStringNotContainsString('JUWRI LTD', $html);
        $this->assertStringNotContainsString('class="mk-legal__nav"', $html);
    }

    public function test_with_legal_pages_it_names_the_company_and_links_the_privacy_policy(): void
    {
        config(['legal.enabled' => true]);

        $html = $this->get('/cookies')->assertOk()->getContent();

        $this->assertStringContainsString('data-cookie-company', $html);
        $this->assertStringContainsString('<a href="'.e(LegalDocuments::url('privacy')).'">'.e('Privacy Policy').'</a>', $html);
        $this->assertStringContainsString('class="mk-legal__nav"', $html);

        // The legal footer column and every legal page link the list.
        $footer = (string) strstr($html, '<footer class="mk-foot">');
        $this->assertStringContainsString('<a href="'.e(route('legal.cookies')).'">'.e(__('cookies.title')).'</a>', $footer);
        $terms = $this->get('/terms')->getContent();
        $this->assertStringContainsString('<a href="'.e(route('legal.cookies')).'">'.e(__('cookies.title')).'</a>', (string) strstr($terms, 'class="mk-legal__nav"'));
    }

    public function test_without_legal_pages_the_footer_bar_links_the_list(): void
    {
        $html = $this->get(route('contact'))->assertOk()->getContent();

        $bar = (string) strstr($html, '<div class="mk-foot__bar">');
        $this->assertStringContainsString('<li><a href="'.e(route('legal.cookies')).'">'.e(__('cookies.title')).'</a></li>', $bar);
    }

    public function test_the_list_is_translated(): void
    {
        $html = $this->withSession(['locale' => 'ar'])->get('/cookies')->assertOk()->getContent();

        $this->assertStringContainsString(e(__('cookies.title', [], 'ar')), $html);
        $this->assertStringContainsString(e(__('cookies.purpose.consent', [], 'ar')), $html);
        $this->assertStringContainsString(e(__('cookies.lifetime.session', [], 'ar')), $html);
        $this->assertStringNotContainsString(e(__('cookies.purpose.consent', [], 'en')), $html);
    }

    public function test_the_banner_reads_the_registry(): void
    {
        config(['legal.enabled' => true]);
        $html = $this->get(route('contact'))->assertOk()->getContent();

        foreach (CookieRegistry::optionalCategories() as $category) {
            $this->assertStringContainsString('data-ckc-cat="'.$category.'"', $html);
        }
        $this->assertStringContainsString('<a href="'.e(route('legal.cookies')).'" data-ckc-list>'.e(__('cookies.list_link')).'</a>', $html);
        $this->assertStringContainsString('PATTERNS = '.json_encode(CookieRegistry::dropPatterns()), $html);

        // The patterns the banner removes on revoke match exactly the registry's cookies of that category.
        foreach (CookieRegistry::dropPatterns() as $category => $pattern) {
            foreach (CookieRegistry::all() as $key => $cookie) {
                $sample = $cookie['party'] === 'third' ? str_replace('*', 'X1', $cookie['name']) : null;
                if ($sample === null) {
                    continue;
                }
                $this->assertSame($cookie['category'] === $category, preg_match('/'.$pattern.'/', $sample) === 1, "$category / $key");
            }
        }
    }

    public function test_the_consent_gate_takes_category_and_lifetime_from_the_registry(): void
    {
        $this->assertSame(['analytics', 'marketing'], CookieRegistry::optionalCategories());
        $this->assertSame(30, CookieRegistry::days('mb_ref'));
        $this->assertSame(30, CookieRegistry::days('ref_code'));

        $request = Request::create('/some-store?ref=XYZ98765', 'GET', [], [CookieConsent::COOKIE => 'v1.a0.m1']);
        $request->setLaravelSession(app('session')->driver());
        (new CaptureGuestReferral)->handle($request, fn () => response('ok'));
        $queued = collect(Cookie::getQueuedCookies())->keyBy(fn ($c) => $c->getName());
        $this->assertTrue($queued->has('mb_ref'));
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $queued['mb_ref']->getExpiresTime(), 5);

        try {
            CookieConsent::rememberAttribution($request, 'not_listed', 'x');
            $this->fail('an unlisted cookie must not be written');
        } catch (\LogicException) {
            $this->assertNotContains('not_listed', array_map(fn ($c) => $c->getName(), Cookie::getQueuedCookies()));
        }
        $this->expectException(\LogicException::class);
        CookieRegistry::days(CookieConsent::COOKIE); // essential: never consent-gated
    }

    public function test_every_cookie_the_code_sets_is_in_the_registry(): void
    {
        $found = [];
        $roots = [app_path(), ...array_column(EditionPackages::all(), 'path'), resource_path('views'), base_path('routes'), dirname(base_path()).'/assets'];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (File::allFiles($root) as $file) {
                $path = $file->getPathname();
                if (! preg_match('/\.(php|js)$/', $path) || str_ends_with($path, '.min.js')
                    || preg_match('~/(tests|vendor|node_modules)/~', $path)) {
                    continue;
                }
                $src = $file->getContents();
                foreach (self::SETTERS as $regex) {
                    preg_match_all($regex, $src, $m);
                    foreach ($m[1] as $name) {
                        $found[$name][] = $file->getRelativePathname();
                    }
                }
            }
        }
        $this->assertArrayHasKey('site_consent', $found, 'the scan sees the code');

        $unlisted = [];
        $seen = [];
        foreach ($found as $name => $files) {
            $key = CookieRegistry::forName($name);
            if ($key === null) {
                $unlisted[] = $name.' ('.implode(', ', array_slice(array_unique($files), 0, 3)).')';
            } else {
                $seen[$key] = true;
            }
        }
        $this->assertSame([], $unlisted, "cookies set by the code but missing from CookieRegistry (and so from /cookies):\n".implode("\n", $unlisted));

        $stale = [];
        foreach (CookieRegistry::all() as $key => $cookie) {
            if ($cookie['party'] === 'first' && ! in_array($key, self::NOT_IN_OUR_CODE, true) && ! isset($seen[$key])) {
                $stale[] = $key;
            }
        }
        $this->assertSame([], $stale, 'first-party cookies listed in CookieRegistry that no code sets any more');
    }

    public function test_every_locale_has_the_same_keys_placeholders_and_links_and_is_translated(): void
    {
        $en = require lang_path('en/cookies.php');
        $purposes = array_unique(array_column(CookieRegistry::all(), 'purpose'));
        foreach ($purposes as $purpose) {
            $this->assertArrayHasKey('purpose.'.$purpose, $en, "registry purpose {$purpose} has no text");
        }
        foreach (CookieRegistry::CATEGORIES as $category) {
            $this->assertArrayHasKey('category.'.$category, $en);
            $this->assertArrayHasKey('category_note.'.$category, $en);
        }
        foreach (self::LOCALES as $locale) {
            $lines = require lang_path($locale.'/cookies.php');
            $this->assertSame(array_keys($en), array_keys($lines), "$locale: keys");
            foreach ($en as $key => $english) {
                preg_match_all('/(?<![\w@]):[a-z_]+\b/', $english, $a);
                preg_match_all('/(?<![\w@]):[a-z_]+\b/', $lines[$key], $b);
                $this->assertSame($a[0], $b[0], "$locale $key: placeholders");
                preg_match_all('~\]\((/[^)]*)\)~', $english, $a);
                preg_match_all('~\]\((/[^)]*)\)~', $lines[$key], $b);
                $this->assertSame($a[1], $b[1], "$locale $key: links");
                if ($locale !== 'en' && ! in_array($key, self::SAME_AS_ENGLISH[$locale] ?? [], true)) {
                    $this->assertNotSame($english, $lines[$key], "$locale $key: still English");
                }
            }
        }
    }

    public function test_the_path_is_a_reserved_store_slug(): void
    {
        $this->assertTrue(ReservedSlug::isReserved('cookies'));
    }

    /**
     * P1-12: every cookie name a visitor can see (/cookies, browser tools — also on a reseller's white-label host) is
     * brand-neutral: the default session cookie (was APP_NAME-derived `juwri_session`), the split-mode per-origin
     * session cookies and every registry entry.
     */
    public function test_cookie_names_carry_no_platform_brand(): void
    {
        $this->assertSame('site_session', config('session.cookie'));
        $names = [(string) config('session.cookie'), ...array_map('strval', (array) config('hosts.cookies'))];
        foreach (CookieRegistry::all() as $entry) {
            $names[] = $entry['name'];
        }
        foreach ($names as $name) {
            $this->assertStringNotContainsStringIgnoringCase('juwri', $name);
        }
        $this->assertSame(['site_app', 'site_admin', 'site_store', 'site_marketing'], array_values((array) config('hosts.cookies')));
        $this->get('/cookies')->assertOk()->assertSee('<code dir="ltr">site_session</code>', false);
    }
}
