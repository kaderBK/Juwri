<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Found by the P1-12 Arabic journey: the superadmin dashboard counted "7 paying merchants", "0 cancels/expires" and "0 trials
 * started" with trans_choice() on a literal English sentence, which no locale can translate (the first segment IS the key).
 * They are ___n() keys now, with every locale's own plural forms.
 */
class PluralLabelsTranslatedTest extends TestCase
{
    private const KEYS = ['n_paying_merchant', 'n_cancelexpire', 'n_trial_started', 'n_account'];

    protected function tearDown(): void
    {
        app()->setLocale('en');
        parent::tearDown();
    }

    public function test_every_locale_has_a_plural_line_for_every_count(): void
    {
        $english = include base_path('lang/en/lang.php');

        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            $table = include base_path("lang/$locale/lang.php");
            foreach (self::KEYS as $key) {
                $this->assertArrayHasKey($key, $table, "[$locale] $key missing");
                $this->assertNotSame($english[$key], $table[$key], "[$locale] $key is still the English line");
                foreach ([0, 1, 2, 3, 5, 11, 21, 100] as $count) {
                    $line = trans_choice('lang.'.$key, $count, ['count' => $count], $locale);
                    $this->assertNotSame('lang.'.$key, $line, "[$locale] $key/$count unresolved");
                    $this->assertNotSame('', trim($line), "[$locale] $key/$count empty");
                    $this->assertStringNotContainsString(':count', $line, "[$locale] $key/$count placeholder left");
                }
                // a number the line must print: 3+ everywhere (Arabic words out 1 and 2)
                $this->assertStringContainsString('5', trans_choice('lang.'.$key, 5, ['count' => 5], $locale), "[$locale] $key/5");
            }
        }
    }

    public function test_the_arabic_dashboard_forms_follow_the_six_arabic_plural_rules(): void
    {
        app()->setLocale('ar');

        $this->assertSame('تاجر واحد يدفع', ___n('paying merchant', 1));
        $this->assertSame('تاجران يدفعان', ___n('paying merchant', 2));
        $this->assertSame('7 تجار يدفعون', ___n('paying merchant', 7));
        $this->assertSame('12 تاجرًا يدفعون', ___n('paying merchant', 12));
        $this->assertSame('100 تاجر يدفعون', ___n('paying merchant', 100));
        // the slug of 'cancel/expire' is n_cancelexpire (Str::slug drops the slash): the Arabic line must actually be found
        $this->assertSame('إلغاء/انتهاء واحد', ___n('cancel/expire', 1));
        app()->setLocale('en');
        $this->assertSame('0 paying merchants', ___n('paying merchant', 0));
        $this->assertSame('1 paying merchant', ___n('paying merchant', 1));
        $this->assertSame('1 cancel/expire', ___n('cancel/expire', 1));
    }

    /** @return list<string> Module folders of an installed edition package (siblings of app/); none in a Community checkout. */
    private function editionModuleDirs(): array
    {
        return array_values(array_filter(
            glob(base_path('*/Modules'), GLOB_ONLYDIR) ?: [],
            fn (string $dir): bool => basename(dirname($dir)) !== 'app',
        ));
    }

    public function test_no_view_passes_a_literal_english_plural_to_trans_choice(): void
    {
        $offenders = [];
        $finder = (new Finder)->files()->name('*.blade.php')->in(array_merge([base_path('resources/views')], $this->editionModuleDirs()));
        foreach ($finder as $file) {
            if (preg_match("/trans_choice\(\s*':count[^']*\|/", $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'literal English plural in trans_choice(): use ___n() (key + per-locale forms)');
    }
}
