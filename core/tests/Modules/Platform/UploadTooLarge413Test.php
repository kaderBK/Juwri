<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Support\ApiError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Support\ErrorPage;
use Juwri\Core\Support\UploadLimit;
use Tests\TestCase;

/**
 * P6-212: when a request body crosses the host's `post_max_size` (Laravel's
 * ValidatePostSize middleware), the owner used to see a raw 413 with no hint
 * of which PHP limit to raise. We now resolve it to the friendly, translated
 * page that the 5xx stack already uses, naming the real per-file ceiling.
 *
 * Three paths must behave consistently:
 *   1. HTML form POST → translated page, placeholders substituted in.
 *   2. JSON API request on api/v1 → one envelope shape (`message`, `code`, `details.max_mb`).
 *   3. JSON API request on /api/* (non-v1) → same envelope, same `details.max_mb`.
 *
 * All 11 locales must carry the source text and stay on the matching script.
 */
class UploadTooLarge413Test extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['ar', 'de', 'en', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'app.locale' => 'en']);
        app()->setLocale('en');

        // Generic /__t/413 → abort(413) path. Same shape as ErrorPagesMaintenanceTest
        // uses for the 5xx stack, so we can swap route targets and reuse assertions.
        Route::get('/__t/413', fn () => abort(413, 'upstream raw 413'));

        // Dedicated throw routes for the JSON paths so we can hit them with raw
        // PostTooLargeException instances (the same class Laravel's ValidatePostSize
        // middleware raises before any route ever runs).
        Route::get('/__t/throw/413', fn () => throw new PostTooLargeException());
        Route::get('/api/v1/throw-upload', fn () => throw new PostTooLargeException());
        Route::get('/api/raw/throw-upload', fn () => throw new PostTooLargeException());
    }

    public function test_html_413_renders_the_friendly_translated_page_with_recovery_hint(): void
    {
        $res = $this->withHeaders(['Accept-Language' => 'en'])->get('/__t/413');

        $res->assertStatus(413);
        $html = $res->getContent();
        $this->assertStringContainsString('lang="en"', $html);
        $this->assertStringContainsString((string) 413, $html);
        // The error page chrome (noindex) must remain — this is the same ErrorPage
        // path as the 5xx stack, not a throwaway substitute.
        $this->assertStringContainsString('noindex', $html);
        // The translated title shows up via the slug-key lookup. Slug of "File too large"
        // is `file_too_large`, so `trans('lang.file_too_large', [], 'en')` resolves to the
        // English value — assert the diag hint is on the page.
        $this->assertStringContainsString(e(ErrorPage::t('File too large', 'en')), $html);
        // `upload_max_filesize` is the PHP directive the description points owners at.
        $this->assertStringContainsString('upload_max_filesize', $html);
        $this->assertStringContainsString('post_max_size', $html);
    }

    public function test_html_413_in_every_locale_with_the_right_script(): void
    {
        // The host cap substituted depends on the live PHP ini; this test only checks the
        // page is translated and shows the diagnostic terms, not a specific number.
        $script = [
            'ar' => '/\p{Arabic}/u', 'ur' => '/\p{Arabic}/u', 'hi' => '/\p{Devanagari}/u',
            'ru' => '/\p{Cyrillic}/u', 'zh' => '/\p{Han}/u',
        ];
        foreach (self::LOCALES as $locale) {
            $html = $this->withHeaders(['Accept-Language' => $locale])->get('/__t/413')->assertStatus(413)->getContent();

            $dir = in_array($locale, ['ar', 'ur'], true) ? 'rtl' : 'ltr';
            $this->assertStringContainsString('lang="'.$locale.'"', $html, "$locale lang attr missing");
            $this->assertStringContainsString('dir="'.$dir.'"', $html, "$locale dir attr wrong");
            // The translated title must come from the slug-key lookup (never the
            // English literal in non-English locales).
            $title = ErrorPage::t('File too large', $locale);
            if ($locale !== 'en') {
                $this->assertNotSame('File too large', $title, "[$locale] title was untranslated");
            }
            $this->assertStringContainsString(e($title), $html, "[$locale] title missing on rendered page");
            if (isset($script[$locale])) {
                $this->assertMatchesRegularExpression($script[$locale], $title, "[$locale] title is not in the locale's script");
            }
            // The PHP directive is part of the English source description — even when the
            // rest is translated, those tokens must survive (a recovery hint is useless if
            // the directive name is rendered as a localised string).
            $this->assertStringContainsString('upload_max_filesize', $html, "$locale missing upload_max_filesize hint");
            $this->assertStringContainsString('post_max_size', $html, "$locale missing post_max_size hint");
        }
    }

    public function test_json_413_on_api_v1_returns_the_envelope_with_max_mb(): void
    {
        $res = $this->withHeaders(['Accept-Language' => 'en'])
            ->getJson('/api/v1/throw-upload');

        $res->assertStatus(413);
        $res->assertJsonStructure(['message', 'code']);
        $res->assertJsonPath('code', 'payload_too_large');
        // The live cap is whatever the test runner's PHP ini reports; we only assert
        // the field exists and is non-negative (a parse failure in `ini_get` would make
        // it null and that path is exercised in the apierror-from-exception test below).
        $body = json_decode((string) $res->getContent(), true);
        $cap = $body['details']['max_mb'] ?? null;
        $live = UploadLimit::effectiveMaxMb();
        $this->assertIsInt($cap);
        $this->assertSame($live > 0 ? $live : null, $cap);
        $res->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_json_413_on_non_v1_api_routes_returns_the_same_envelope(): void
    {
        $res = $this->getJson('/api/raw/throw-upload');

        $res->assertStatus(413);
        $res->assertJsonStructure(['message', 'code']);
        $res->assertJsonPath('code', 'payload_too_large');
        $body = json_decode((string) $res->getContent(), true);
        $this->assertArrayHasKey('max_mb', $body['details'] ?? []);
    }

    public function test_api_error_from_exception_maps_post_too_large_to_a_machine_recovery_hint(): void
    {
        // The api/v1 wrapper calls `ApiError::fromException` directly; PostTooLargeException
        // gets a dedicated branch (above the generic HttpExceptionInterface tail) so the
        // `details.max_mb` is always set on the envelope, not just on the rendered page.
        $json = ApiError::fromException(new PostTooLargeException('upstream fallback'));

        $this->assertSame(413, $json->getStatusCode());
        $body = json_decode((string) $json->getContent(), true);
        $this->assertSame('payload_too_large', $body['code']);
        $live = UploadLimit::effectiveMaxMb();
        if ($live > 0) {
            $this->assertSame($live, $body['details']['max_mb']);
        } else {
            $this->assertArrayNotHasKey('max_mb', $body['details'] ?? []);
        }
        $this->assertStringContainsString('upload_max_filesize', $body['message']);
    }

    public function test_every_locale_has_its_translated_413_strings_in_its_own_language(): void
    {
        // The ErrorPagesMaintenanceTest::test_all_eleven_locales_have_every_error_string_in_their_own_language
        // already walks every status in SOURCE — 413 must be in the same walk. Both the
        // title and the (placeholder-bearing) description must be slug-keyed in every
        // locale file, with the description keeping the `:limit` placeholder so the
        // `:limit → MB` substitution on render is unambiguous.
        $script = [
            'ar' => '/\p{Arabic}/u', 'ur' => '/\p{Arabic}/u', 'hi' => '/\p{Devanagari}/u',
            'ru' => '/\p{Cyrillic}/u', 'zh' => '/\p{Han}/u',
        ];
        foreach (self::LOCALES as $locale) {
            $title = ErrorPage::t('File too large', $locale);
            $description = ErrorPage::t('Your host stopped this upload — the file exceeds :limit MB. To send a larger file, ask your host to raise upload_max_filesize and post_max_size in php.ini.', $locale);

            if ($locale !== 'en') {
                $this->assertNotSame('File too large', $title, "[$locale] title is untranslated");
                $this->assertNotSame(ErrorPage::t('Your host stopped this upload — the file exceeds :limit MB. To send a larger file, ask your host to raise upload_max_filesize and post_max_size in php.ini.', 'en'), $description, "[$locale] description is untranslated");
            }
            $this->assertNotSame('', $description, "[$locale] description is empty");
            $this->assertStringContainsString(':limit', $description, "[$locale] description must keep the :limit placeholder so ErrorPage can substitute the host cap");
            // Both directive names survive translation (a recovery hint is useless
            // if the directive name is rendered as a localised string).
            $this->assertStringContainsString('upload_max_filesize', $description, "[$locale] description lost upload_max_filesize");
            $this->assertStringContainsString('post_max_size', $description, "[$locale] description lost post_max_size");

            // Placeholder substitution: `:limit` becomes the host cap number.
            $interpolated = ErrorPage::interpolate($description, ['limit' => 42]);
            $this->assertStringContainsString('42', $interpolated, "[$locale] description does not interpolate :limit");
            $this->assertStringNotContainsString(':limit', $interpolated, "[$locale] :limit placeholder was not consumed");

            if (isset($script[$locale])) {
                $this->assertMatchesRegularExpression($script[$locale], $title, "[$locale] title is not in the locale's script");
            }
        }
    }
}
