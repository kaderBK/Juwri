<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Modules\Content\Http\Controllers\HelpCenterController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * P4-16: the help center serves docs/users/<locale>/<page>.md for any active locale, falls back to the English page per
 * page when that locale has no translation, and never reads outside the docs folder.
 */
class HelpCenterLocaleTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> files this test created (removed in tearDown; shipped translations are never touched) */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function allowLocale(string $code): void
    {
        config(['laravellocalization.supportedLocales' => array_merge(
            (array) config('laravellocalization.supportedLocales', []),
            [$code => ['name' => $code, 'script' => 'Latn', 'native' => $code, 'regional' => $code.'_'.strtoupper($code)]],
        )]);
    }

    public function test_arabic_locale_serves_the_arabic_translation(): void
    {
        $this->allowLocale('ar');
        app()->setLocale('ar');

        $res = $this->get('/help-center/pos');

        $res->assertOk();
        // the Arabic body of docs/users/ar/pos.md ("نقطة البيع"); the English page says "Point of sale"
        $this->assertStringContainsString('نقطة البيع', $res->getContent());
    }

    public function test_active_non_english_locale_serves_its_translated_page(): void
    {
        $marker = 'DE-LOKALISIERT-'.Str::random(12);
        $path = resource_path('docs/users/de/pos.md');
        $this->assertFileDoesNotExist($path, 'the test needs a page the shipped de folder does not have');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, "---\ntitle: Verkaufsstelle\naudience: owner\nupdated: 2026-10-12\n---\n\n# Verkaufen offline\n\n{$marker}\n");
        $this->created[] = $path;

        $this->allowLocale('de');
        app()->setLocale('de');

        $res = $this->get('/help-center/pos');

        $res->assertOk();
        $this->assertSame('de', app()->getLocale());
        $this->assertStringContainsString($marker, $res->getContent());
    }

    public function test_locale_without_a_translated_file_falls_back_to_english(): void
    {
        // de has no docs/users/de/pos.md (the shipped de folder holds only the offline page), so the English page is served
        $this->assertFileDoesNotExist(resource_path('docs/users/de/pos.md'));
        $this->allowLocale('de');
        app()->setLocale('de');

        $res = $this->get('/help-center/pos');

        $res->assertOk();
        $this->assertSame('de', app()->getLocale(), 'the request ran in the de locale');
        $this->assertStringContainsString('point of sale', strtolower($res->getContent()));
        $this->assertStringNotContainsString('نقطة البيع', $res->getContent());
    }

    public function test_hostile_slug_and_locale_cannot_escape_the_docs_folder(): void
    {
        // hostile slugs never reach the filesystem: the route constraint and the controller regex both reject them
        $this->get('/help-center/..%2F..%2F.env')->assertNotFound();
        $this->get('/help-center/..%2Fx')->assertNotFound();
        $this->get('/help-center/README')->assertNotFound();

        // the framework refuses a locale with path characters outright (first line of defence) ...
        foreach (['../../../etc', 'ar/../../x', "ar\0", 'a\\b'] as $hostile) {
            try {
                app()->setLocale($hostile);
                $this->fail('setLocale accepted '.json_encode($hostile));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        // ... and the controller's own gate (second line) sends any other odd or unsupported value to the English source
        $method = new \ReflectionMethod(HelpCenterController::class, 'localizedPath');
        $method->setAccessible(true);
        foreach (['AR', 'a.b', 'xx', 'toolonglocale'] as $odd) {
            app()->setLocale($odd);
            $resolved = $method->invoke(new HelpCenterController, 'users', 'pos.md');
            $this->assertSame(resource_path('docs/users/pos.md'), $resolved, 'locale '.json_encode($odd));
        }

        // a hostile NAME is refused (404) before it is joined to any path, whatever the locale
        app()->setLocale('ar');
        foreach (['../pos.md', '../../.env', 'ar/../../pos.md', "pos.md\0", 'pos.php', 'a/b.md'] as $name) {
            try {
                $method->invoke(new HelpCenterController, 'users', $name);
                $this->fail('accepted '.json_encode($name));
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** both paths are fenced: an English source or a translation that is a symlink out of the docs folder is never served */
    public function test_a_symlink_out_of_the_docs_folder_is_not_followed(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'docs-secret-');
        file_put_contents($outside, 'SECRET-OUTSIDE-DOCS');
        $en = resource_path('docs/users/zz-symlink-test.md');
        $de = resource_path('docs/users/de/zz-symlink-test.md');
        $this->created[] = $en;
        $this->created[] = $de;
        $this->created[] = $outside;
        $method = new \ReflectionMethod(HelpCenterController::class, 'localizedPath');
        $method->setAccessible(true);

        // English source is a symlink to a file outside: 404, not the outside file
        symlink($outside, $en);
        app()->setLocale('en');
        try {
            $method->invoke(new HelpCenterController, 'users', 'zz-symlink-test.md');
            $this->fail('followed an English symlink out of the docs folder');
        } catch (NotFoundHttpException) {
            $this->addToAssertionCount(1);
        }

        // a real English page whose translation is a symlink out: the English page is served, never the outside file
        @unlink($en);
        file_put_contents($en, "# English\n");
        File::ensureDirectoryExists(dirname($de));
        symlink($outside, $de);
        $this->allowLocale('de');
        app()->setLocale('de');
        $this->assertSame(realpath($en), $method->invoke(new HelpCenterController, 'users', 'zz-symlink-test.md'));
    }
}
