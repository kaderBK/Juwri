<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Legal;

use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Referral;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Legal\CookieConsent;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Marketing\Http\Middleware\CaptureGuestReferral;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-23: strictly necessary cookies only until the visitor chooses. Every analytics / marketing script (platform GA,
 * the store's GA / GTM / Meta Pixel, Tawk chat) is rendered inert and started by the banner after consent; reject is
 * as easy as accept; the choice is a 6-month cookie, changeable from the footer; server-set attribution cookies
 * (referral codes) need marketing consent, else they stay in the session.
 */
class CookieConsentTest extends TestCase
{
    use RefreshDatabase;

    /** Third-party tracker markers: a <script> carrying one of these must be consent-gated. */
    private const TRACKERS = ['googletagmanager.com', 'fbevents.js', 'embed.tawk.to', "fbq('init'", "gtag('config'"];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config([
            'legal.enabled' => true,
            'settings.google_analytics' => (object) ['status' => '1', 'measurement_id' => 'G-PLATFORM1'],
            'settings.tawk_to' => (object) ['status' => '1', 'chat_link' => 'https://tawk.to/chat/abc/def'],
        ]);
    }

    /** Every <script> whose body or src names a tracker must be inert: type="text/plain" + data-cookie-consent. */
    private function assertTrackersGated(string $html): void
    {
        preg_match_all('~<script\b([^>]*)>(.*?)</script>~is', $html, $m, PREG_SET_ORDER);
        $seen = 0;
        foreach ($m as [$all, $attrs, $body]) {
            if (! Str::contains($attrs.$body, self::TRACKERS)) {
                continue;
            }
            if (Str::contains($attrs.$body, 'siteCookieConsent')) {
                continue; // the banner script itself
            }
            $seen++;
            $this->assertStringContainsString('type="text/plain"', $attrs, 'tracker script not inert: '.Str::limit($all, 160));
            $this->assertMatchesRegularExpression('~data-cookie-consent="(analytics|marketing)"~', $attrs);
        }
        $this->assertGreaterThan(0, $seen, 'expected at least one tracker on the page');
        $this->assertStringNotContainsString('facebook.com/tr?', $html, 'no-JS pixel fallback fires without consent');
        $this->assertStringNotContainsString('googletagmanager.com/ns.html', $html, 'no-JS GTM fallback loads without consent');
    }

    private function assertBanner(string $html, bool $forced): void
    {
        $this->assertStringContainsString('id="siteCookieConsent"', $html);
        $this->assertStringContainsString('data-force="'.($forced ? '1' : '0').'"', $html);
        // Reject is as easy as accept: both are first-level buttons of the same class.
        $this->assertMatchesRegularExpression('~<button type="button" class="ckc__btn" data-ckc="reject">'.preg_quote(e(__('site.cookie.reject')), '~').'</button>~', $html);
        $this->assertMatchesRegularExpression('~<button type="button" class="ckc__btn" data-ckc="accept">'.preg_quote(e(__('site.cookie.accept')), '~').'</button>~', $html);
        foreach (['essential', 'analytics', 'marketing'] as $cat) {
            $this->assertStringContainsString(e(__('site.cookie.settings.'.$cat)), $html);
        }
        $this->assertStringContainsString('Max-Age=\' + (DAYS * 86400)', $html);
        // Regression (browser check): the script's global must not share the banner's id — window.<id> is the element
        // (named access), so a guard on it returned before anything was wired.
        $this->assertStringNotContainsString('window.juwriCookieConsent', $html);
        $this->assertStringContainsString('window.siteConsent = {', $html);
    }

    public function test_marketing_pages_gate_platform_analytics_and_chat_and_always_ask(): void
    {
        $html = $this->get(route('contact'))->assertOk()->getContent();

        $this->assertTrackersGated($html);
        $this->assertMatchesRegularExpression('~<script type="text/plain" data-cookie-consent="analytics"[^>]*>\s*window\.dataLayer~', $html, 'platform GA');
        $this->assertMatchesRegularExpression('~<script type="text/plain" data-cookie-consent="analytics"[^>]*>\s*var Tawk_API~', $html, 'Tawk chat');
        $this->assertBanner($html, true);
        $this->assertStringContainsString('data-cookie-settings', $html, 'footer link to change the choice');
    }

    public function test_storefront_gates_the_store_analytics_and_pixel(): void
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'allow_ordering' => 1],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'ckc-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Cookie Store',
            'sub_title' => 'S', 'description' => 'D', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        StoreOption::updateStoreOption($store->id, 'integration_ga_id', 'G-STORE123');
        StoreOption::updateStoreOption($store->id, 'integration_meta_pixel_id', '123456789');

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertTrackersGated($html);
        $this->assertStringContainsString('data-cookie-consent="analytics" data-src="https://www.googletagmanager.com/gtag/js?id=G-STORE123"', $html);
        $this->assertMatchesRegularExpression('~<script type="text/plain" data-cookie-consent="marketing"[^>]*>\s*!function\(f,b,e,v,n,t,s\)~', $html, 'Meta Pixel needs marketing consent');
        $this->assertBanner($html, false); // shown by the script because gated scripts are on the page
    }

    public function test_no_view_ships_an_ungated_tracker(): void
    {
        // Static guard over every Blade view: a <script> that loads or calls a known tracker must be consent-gated.
        $offenders = [];
        // The installed edition packages' folders, asked from Composer (none in the Community build) — never a fixed
        // package path, which does not exist in the Community mirror.
        $roots = [resource_path('views'), base_path('app'), ...array_column(EditionPackages::all(), 'path')];
        foreach ($roots as $root) {
            foreach (File::allFiles($root) as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $src = $file->getContents();
                if (! Str::contains($src, self::TRACKERS)) {
                    continue;
                }
                preg_match_all('~<script\b([^>]*)>(.*?)</script>~is', $src, $m, PREG_SET_ORDER);
                foreach ($m as [$all, $attrs, $body]) {
                    if (Str::contains($attrs.$body, self::TRACKERS) && ! Str::contains($attrs, 'data-cookie-consent') && ! Str::contains($body, 'siteCookieConsent')) {
                        $offenders[] = $file->getRelativePathname().': '.Str::limit(trim($all), 120);
                    }
                }
            }
        }
        $this->assertSame([], $offenders, "Tracker scripts without consent gating:\n".implode("\n", $offenders));
    }

    public function test_the_choice_cookie_is_read_server_side(): void
    {
        $this->assertSame(['decided' => false, 'analytics' => false, 'marketing' => false], CookieConsent::fromRequest(Request::create('/')));
        $req = Request::create('/', 'GET', [], [CookieConsent::COOKIE => 'v1.a1.m0']);
        $this->assertSame(['decided' => true, 'analytics' => true, 'marketing' => false], CookieConsent::fromRequest($req));
        $this->assertTrue(CookieConsent::allows('analytics', $req));
        $this->assertFalse(CookieConsent::allows('marketing', $req));
        $this->assertFalse(CookieConsent::allows('marketing', Request::create('/', 'GET', [], [CookieConsent::COOKIE => 'v1.a1.m1x'])));
        $this->assertContains(CookieConsent::COOKIE, (fn () => $this->except)->call(app(EncryptCookies::class)), 'the banner writes it in plain text');
    }

    public function test_referral_attribution_needs_marketing_consent_else_stays_in_the_session(): void
    {
        // Store guest refer-a-friend (?ref= on a storefront).
        $request = Request::create('/some-store?ref=ABC12345', 'GET');
        $request->setLaravelSession($session = app('session')->driver());
        (new CaptureGuestReferral)->handle($request, fn () => response('ok'));
        $this->assertNotContains('mb_ref', array_map(fn ($c) => $c->getName(), Cookie::getQueuedCookies()), 'no 30-day cookie without consent');
        $this->assertSame('ABC12345', $session->get('attribution.mb_ref'));
        $this->assertSame('ABC12345', CookieConsent::attribution($request, 'mb_ref'));

        // Platform referral (?ref= on the sign-up page).
        $referrer = User::factory()->create(['status' => 1]);
        $code = Referral::forUser($referrer)->code;
        $this->get(route('register', ['ref' => $code]))->assertOk()->assertCookieMissing('ref_code')->assertSessionHas('attribution.ref_code', $code);

        $this->withUnencryptedCookie(CookieConsent::COOKIE, 'v1.a0.m1')
            ->get(route('register', ['ref' => $code]))->assertOk()->assertCookie('ref_code', $code);
    }

    /**
     * Found by the P1-12 browser journey: withdrawing analytics removed _ga at once, but the still-running Google tag
     * wrote its session cookie again while the page unloaded, so _ga_<id> outlived the withdrawal. Every page load now
     * removes the cookies of each category the visitor has NOT consented to (before any tag could start).
     */
    public function test_every_load_removes_the_cookies_of_a_withdrawn_category(): void
    {
        $html = $this->get(route('contact'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '~function boot\(\) \{\s*var c = read\(\);\s*if \(c\) \{[^}]*?Object\.keys\(DROP\)\.forEach\(function \(cat\) \{ if \(!c\[cat\]\) \{ drop\(DROP\[cat\]\); \} \}\);\s*activate\(c\);~s',
            $html,
            'boot() must drop the cookies of every non-consented category before activating the consented scripts'
        );
    }
}
