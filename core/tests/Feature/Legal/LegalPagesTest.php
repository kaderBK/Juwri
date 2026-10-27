<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Legal;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Juwri\Core\Legal\LegalDocuments;
use Juwri\Core\Legal\LegalPage;
use Juwri\Core\Legal\SecurityReport;
use Juwri\Core\Models\Language;
use Juwri\Core\Rules\ReservedSlug;
use Juwri\Core\Support\Views\PublicSiteViewHelpers;
use Tests\TestCase;

/**
 * P1-23: JUWRI LTD's legal pages render from resources/legal/{en,ar}/*.md through one template with a locale switch;
 * {{FILL}} / {{VERIFY}} markers resolve from config/legal.php and can never ship (legal:check fails, production 503);
 * /.well-known/security.txt follows RFC 9116; the marketing footer links every page and carries the company line.
 */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    private const TITLES = [
        'terms' => 'juwri Subscription Terms',
        'privacy' => 'juwri Privacy Policy',
        'dpa' => 'juwri Data Processing Addendum',
        'aup' => 'juwri Acceptable Use Policy',
        'sla' => 'juwri Service Level Agreement',
        'subprocessors' => 'juwri Sub-processors',
        'security' => 'Security at juwri',
        'free_reseller' => 'juwri Free Reseller Terms', // reseller rework v2: the Free reseller tier click-through
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Pinned: Terms / Free Terms 1.1 (J51d) are undated until the change notice goes out (J51e), so 1.0 is in force.
        $this->travelTo(Carbon::parse('2026-10-19 10:00:00', 'UTC'));
        app()->setLocale('en');
        foreach (['en' => ['English', 'ltr'], 'ar' => ['Arabic', 'rtl'], 'fr' => ['French', 'ltr']] as $code => [$name, $dir]) {
            Language::updateOrCreate(['code' => $code], ['name' => $name, 'direction' => $dir, 'active' => 1]);
        }
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['legal.enabled' => true]);
    }

    /** The shipped texts have no open marker any more; the marker tests read the pre-fill texts kept as a fixture. */
    private function useTextsWithOpenMarkers(): void
    {
        config(['legal.source_path' => base_path('tests/Fixtures/legal-open')]);
    }

    private function fillEverything(): void
    {
        foreach (array_keys((array) config('legal.fill')) as $key) {
            config(['legal.fill.'.$key => 'Test '.$key]);
        }
        foreach (array_keys((array) config('legal.verify')) as $key) {
            config(['legal.verify.'.$key => true]);
        }
    }

    public function test_every_page_renders_its_english_text_with_the_header_strip(): void
    {
        $this->fillEverything();
        $this->assertSame(array_keys(self::TITLES), array_keys(LegalDocuments::all()));

        foreach (self::TITLES as $document => $title) {
            $url = LegalDocuments::url($document);
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('<h1 class="mk-h1">'.e($title).'</h1>', $html, $document);
            $date = $document === 'free_reseller' ? 'October 5, 2026' : 'October 4, 2026';
            $this->assertStringContainsString(e(__('site.legal.version', ['version' => '1.0', 'date' => $date])), $html, $document);
            $this->assertStringContainsString(e(__('site.legal.changes')), $html, $document);
            $this->assertStringContainsString(e(__('site.legal.contact')), $html, $document);
            $this->assertStringContainsString(e(__('site.legal.updated', ['date' => $date])), $html, "$document: P6-121 last-updated / not-legal-advice note");
            $this->assertStringNotContainsString('{{', $html, $document);
            $this->assertStringNotContainsString('legal-todo', $html, $document);
            $mdLine = in_array($document, ['terms', 'free_reseller'], true) ? '<p>Version 1.1</p>' : 'Version 1.0 · Effective 4 October 2026';
            $this->assertStringNotContainsString($mdLine, $html, "$document: the markdown's own version line is lifted into the strip");
            $this->assertStringContainsString('lang="en" dir="ltr"', $html, $document);
        }
        $this->assertSame(url('/terms'), LegalDocuments::url('terms'));
        $this->assertSame(url('/legal/subprocessors'), LegalDocuments::url('subprocessors'));
    }

    public function test_the_arabic_text_uses_the_same_template_with_the_binding_english_note(): void
    {
        $this->fillEverything();

        $html = $this->withSession(['locale' => 'ar'])->get('/terms')->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="mk-h1">شروط الاشتراك في juwri</h1>', $html);
        $this->assertStringContainsString('lang="ar" dir="rtl"', $html);
        $this->assertStringContainsString('data-legal-translation-note', $html);
        $this->assertStringContainsString(e(__('site.legal.translation_note', [], 'ar')), $html);
        $this->assertStringContainsString('data-legal-langs', $html, 'locale switch');
        $this->assertStringContainsString('hreflang="en"', $html);
        $this->assertStringNotContainsString('> هذه الترجمة', $html, "the markdown's own quote is replaced by the strip");
    }

    public function test_a_locale_without_its_own_text_reads_the_english_one(): void
    {
        $this->fillEverything();

        $html = $this->withSession(['locale' => 'fr'])->get('/legal/aup')->assertOk()->getContent();

        $this->assertStringContainsString('<h1 class="mk-h1">juwri Acceptable Use Policy</h1>', $html);
        $this->assertStringContainsString('data-legal-english-only', $html);
    }

    public function test_vulnerability_reports_point_to_github_not_an_email(): void
    {
        // Unset: a generic phrase, and the page is not held back by an unresolved marker for it.
        config(['legal.security_report_url' => null]);
        $html = $this->get('/security')->assertOk()->getContent();
        $this->assertStringContainsString('GitHub private vulnerability reporting on our repository', $html);
        $this->assertStringNotContainsString('SECURITY_REPORT', $html);
        $this->assertStringNotContainsString('PGP key', $html);

        // Set: the phrase carries the link.
        config(['legal.security_report_url' => 'https://github.com/example/juwri/security/advisories/new']);
        $this->assertStringContainsString('https://github.com/example/juwri/security/advisories/new', $this->get('/security')->assertOk()->getContent());
        $this->assertStringContainsString('GitHub private vulnerability reporting at https://github.com/example/juwri/security/advisories/new', LegalPage::securityReport('en'));
        $this->assertStringNotContainsString('(https://github.com', $this->get('/security')->getContent(), 'no double bracket after the link');
        $this->assertStringContainsString('الإبلاغ الخاص عن الثغرات في GitHub', LegalPage::securityReport('ar'));

        // The email stays only where it is contractual (incident notices / DPA), never as the way to report a vulnerability.
        foreach (['en', 'ar'] as $loc) {
            foreach (['security', 'aup', 'privacy'] as $doc) {
                $md = (string) file_get_contents(resource_path("legal/$loc/$doc.md"));
                $this->assertStringContainsString('{{SECURITY_REPORT}}', $md, "$loc/$doc");
            }
            $this->assertStringNotContainsString('Email:', (string) file_get_contents(resource_path("legal/$loc/security.md")));
        }
    }

    public function test_the_security_report_url_must_be_a_plain_https_address(): void
    {
        foreach (['http://github.com/a/b', 'https://github.com/a b', 'javascript:alert(1)', 'https://x.test/"onmouseover=1', 'https://x.test/<script>', 'github.com/a/b', '   '] as $bad) {
            config(['legal.security_report_url' => $bad]);
            $this->assertNull(SecurityReport::url(), $bad);
            $this->assertStringContainsString('on our repository', LegalPage::securityReport('en'));
            $this->assertStringNotContainsString($bad === '   ' ? 'zzz' : $bad, $this->get('/.well-known/security.txt')->getContent());
        }
        config(['legal.security_report_url' => ' https://github.com/example/juwri/security/advisories/new ']);
        $this->assertSame('https://github.com/example/juwri/security/advisories/new', SecurityReport::url());
        $this->assertStringContainsString("Contact: https://github.com/example/juwri/security/advisories/new\n", $this->get('/.well-known/security.txt')->getContent());
    }

    public function test_placeholders_resolve_from_config_and_never_ship(): void
    {
        $this->useTextsWithOpenMarkers();
        config(['legal.fill.hosting_provider' => 'Acme Cloud', 'legal.verify.encryption_at_rest' => true]);

        $html = $this->get('/security')->assertOk()->getContent();
        $this->assertStringContainsString('juwri Cloud runs on Acme Cloud', $html);
        $this->assertStringNotContainsString('VERIFY: encryption_at_rest', $html);
        // Unresolved: visible marks outside production …
        $this->assertStringContainsString('data-legal-todo="FILL:hosting_region"', $html);
        $this->assertStringContainsString('data-legal-todo="VERIFY:backups"', $html);
        $this->assertStringContainsString('data-legal-unresolved=', $html);

        // … and never rendered in production.
        $this->app['env'] = 'production';
        $this->get('/security')->assertNotFound();
        config(['legal.hide_unfilled' => false]);
        $this->get('/security')->assertStatus(503);
        config(['legal.hide_unfilled' => true]);
        $this->fillEverything();
        $this->get('/security')->assertOk();
    }

    public function test_an_unfilled_page_is_hidden_from_the_footer_in_production_and_shown_once_filled(): void
    {
        $this->useTextsWithOpenMarkers();
        $this->app['env'] = 'production';
        $this->assertTrue(LegalDocuments::hiddenAsUnfilled('sla'));
        $links = PublicSiteViewHelpers::legalFooterLinks(['sla', 'aup']);
        $urls = array_column($links, 0);
        $this->assertNotContains(LegalDocuments::url('sla'), $urls);

        $this->fillEverything();
        $this->assertFalse(LegalDocuments::hiddenAsUnfilled('sla'));
        $this->assertContains(LegalDocuments::url('sla'), array_column(PublicSiteViewHelpers::legalFooterLinks(['sla']), 0));
    }

    public function test_legal_check_fails_until_every_placeholder_is_resolved(): void
    {
        $this->useTextsWithOpenMarkers();
        $this->assertSame(1, Artisan::call('legal:check'));
        $this->assertStringContainsString('unresolved FILL:hosting_provider', Artisan::output());

        $this->fillEverything();
        $this->assertSame(0, Artisan::call('legal:check'), Artisan::output());

        config(['legal.fill.ico_number' => '']);
        $this->assertSame(1, Artisan::call('legal:check'));
        $this->assertStringContainsString('privacy/en: unresolved FILL:ico_number', Artisan::output());

        $this->fillEverything();
        config(['legal.verify.backups' => false]);
        $this->assertSame(1, Artisan::call('legal:check'));
        $this->assertStringContainsString('unresolved VERIFY:backups', Artisan::output());

        $this->fillEverything();
        config(['legal.security_txt.expires' => '2020-01-01T00:00:00.000Z']);
        $this->assertSame(1, Artisan::call('legal:check'));
        $this->assertStringContainsString('security.txt: Expires', Artisan::output());

        config(['legal.security_txt.expires' => now()->addMonths(6)->toIso8601String(), 'legal.documents.terms.versions' => ['1.0' => '2026-10-04', '1.1' => '2026-11-19', '1.2' => '2026-12-31']]);
        $this->assertSame(1, Artisan::call('legal:check'));
        $this->assertStringContainsString("terms/en: text is version '1.1', config/legal.php's newest is '1.2'", Artisan::output());
    }

    public function test_the_texts_use_only_configured_keys_the_same_in_both_languages_and_pass_the_brand_scrub(): void
    {
        $known = array_merge(
            array_map(fn ($k) => 'FILL:'.$k, array_keys((array) config('legal.fill'))),
            array_map(fn ($k) => 'VERIFY:'.$k, array_keys((array) config('legal.verify'))),
        );
        foreach (array_keys(LegalDocuments::all()) as $document) {
            $en = LegalPage::markers($document, 'en');
            $this->assertSame($en, LegalPage::markers($document, 'ar'), "$document: EN/AR placeholders differ");
            $this->assertSame([], array_values(array_diff($en, $known)), "$document: placeholder without a config key");
            foreach (LegalPage::LOCALES as $locale) {
                $text = File::get(LegalPage::path($document, $locale));
                $this->assertDoesNotMatchRegularExpression('/menu'.'[\s_-]?bar[\s_-]?code|quick'.'[\s_-]?qr|quick'.'[\s_-]?cms|by'.'lancer/i', $text); // spelled in pieces (brand scrub)
                $this->assertDoesNotMatchRegularExpression('/\{\{(FILL|VERIFY): /', $text, "$document/$locale: raw handoff marker");
                $this->assertSame(LegalDocuments::latestVersion($document), LegalPage::load($document, $locale)->textVersion, "$document/$locale version");
            }
        }
    }

    public function test_security_txt_follows_rfc_9116(): void
    {
        $res = $this->get('/.well-known/security.txt')->assertOk();
        $this->assertStringStartsWith('text/plain', (string) $res->headers->get('Content-Type'));
        $body = $res->getContent();

        $this->assertStringNotContainsString('mailto:', $body, 'vulnerability reports go through GitHub, no email is published');
        $this->assertStringContainsString('Contact: '.url('/security')."\n", $body);
        $this->assertMatchesRegularExpression('/^Expires: \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/m', $body);
        $this->assertStringContainsString('Policy: '.url('/security'), $body);
        $this->assertStringContainsString('Preferred-Languages: en, ar', $body);
        $this->assertStringContainsString('Canonical: '.url('/.well-known/security.txt'), $body);
        $this->assertStringNotContainsString('Encryption:', $body, 'no key published yet');
        $this->assertStringNotContainsString('Acknowledgments:', $body);
        $this->get('/.well-known/pgp-key.txt')->assertNotFound();

        $key = storage_path('app/legal-test-pgp.txt');
        File::put($key, "-----BEGIN PGP PUBLIC KEY BLOCK-----\ntest\n-----END PGP PUBLIC KEY BLOCK-----\n");
        try {
            config(['legal.security_txt.pgp_key_path' => 'legal-test-pgp.txt']);
            $this->assertStringContainsString('Encryption: '.url('/.well-known/pgp-key.txt'), $this->get('/.well-known/security.txt')->getContent());
            $this->assertStringContainsString('BEGIN PGP PUBLIC KEY BLOCK', $this->get('/.well-known/pgp-key.txt')->assertOk()->getContent());
        } finally {
            File::delete($key);
        }
    }

    public function test_nothing_is_published_without_legal_pages(): void
    {
        config(['legal.enabled' => false]);

        $this->get('/terms')->assertNotFound();
        $this->get('/legal/dpa')->assertNotFound();
        $this->get('/.well-known/security.txt')->assertNotFound();
        $html = $this->get(route('contact'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-legal-company', $html);
        $this->assertStringNotContainsString('14363043', $html);
    }

    public function test_the_footer_links_every_legal_page_and_names_the_company(): void
    {
        $html = $this->get(route('contact'))->assertOk()->getContent();
        $footer = (string) strstr($html, '<footer class="mk-foot">');

        $this->assertStringContainsString('<h2 class="mk-foot__head">'.e(__('site.footer.legal')).'</h2>', $footer);
        foreach (array_keys(LegalDocuments::all()) as $document) {
            $this->assertStringContainsString('<a href="'.e(LegalDocuments::url($document)).'">'.e(LegalDocuments::label($document)).'</a>', $footer, $document);
        }
        $this->assertStringContainsString('JUWRI LTD · Company no. 14363043 · 128 City Road, London, EC1V 2NX, United Kingdom', $footer);
        $this->assertStringContainsString('data-cookie-settings', $footer);
    }

    public function test_the_paths_are_reserved_store_slugs(): void
    {
        foreach (['terms', 'privacy', 'security', 'legal'] as $slug) {
            $this->assertTrue(ReservedSlug::isReserved($slug), $slug);
        }
    }

    public function test_the_cookie_page_carries_the_last_updated_note(): void
    {
        $html = $this->get('/cookies')->assertOk()->getContent();

        $this->assertStringContainsString('data-legal-updated', $html);
        $this->assertStringContainsString(e(__('site.legal.updated', ['date' => 'October 4, 2026'])), $html);
    }

    public function test_no_registered_mark_or_pending_claim_on_the_footer_or_trademark_policy(): void
    {
        // P6-120 (J36): juwri is an unregistered mark. Never the registered sign, never "registration pending".
        $footer = (string) strstr($this->get(route('contact'))->getContent(), '<footer class="mk-foot">');
        $this->assertNotSame('', $footer);
        $policy = (string) file_get_contents(base_path('../TRADEMARKS.md'));

        foreach (['footer' => $footer, 'TRADEMARKS.md' => $policy] as $what => $text) {
            $this->assertStringNotContainsString('®', $text, $what);
            $this->assertStringNotContainsString('&reg;', $text, $what);
            $this->assertDoesNotMatchRegularExpression('/pending|registration application/i', $text, $what);
        }
        $this->assertStringContainsString('unregistered', $policy);
        foreach (['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            $label = __('site.footer.trademarks', [], $locale);
            $this->assertStringNotContainsString('®', $label, $locale);
            $this->assertDoesNotMatchRegularExpression('/pending/i', $label, $locale);
        }
    }
}
