<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Tests\Support\ViewRoots;
use Tests\TestCase;

/**
 * I5 §9 — email/print dates routed through date_formating() so ar/ur/ru
 * recipients see localized month names instead of English ones.
 */
class LocalizedDatesTest extends TestCase
{
    public function test_date_formating_localizes_month_names(): void
    {
        App::setLocale('ar');
        $out = date_formating('2026-03-15', 'M j, Y');
        // Arabic March is مارس — the English "Mar" must not appear.
        $this->assertStringContainsString('مارس', $out);
        $this->assertStringNotContainsString('Mar', $out);
    }

    public function test_no_email_or_print_view_uses_a_raw_english_month_format(): void
    {
        // resources/views and the same folders of the ee modules' Views (P1-13).
        $dirs = [];
        foreach (['emails', 'reseller/portal/emails', 'components/mail', 'staff/pos', 'owner/hotel', 'owner/team/payroll'] as $sub) {
            $dirs = array_merge($dirs, ViewRoots::sub($sub));
        }
        foreach ($dirs as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $src = file_get_contents($file->getPathname());
                $this->assertDoesNotMatchRegularExpression(
                    "/->format\('M ?[jd], Y'\)/",
                    $src,
                    "Raw English month format in {$file->getPathname()}"
                );
            }
        }
    }

    public function test_giftcard_email_uses_date_formating(): void
    {
        $src = file_get_contents(resource_path('views/emails/giftcard.blade.php'));
        $this->assertStringContainsString('date_formating(', $src);
    }

    public function test_date_formating_handles_carbon_and_timestamps_without_deprecation(): void
    {
        App::setLocale('en');

        // A Carbon (or any non-scalar) passed straight into ctype_digit() raised
        // a PHP 8.2 "interpreted as string" deprecation. Fail if one fires while
        // formatting a Carbon — the case /subscription hits for every date.
        set_error_handler(static function ($severity, $message): bool {
            throw new \RuntimeException('unexpected deprecation: '.$message);
        }, E_DEPRECATED);
        try {
            $carbon = Carbon::create(2026, 3, 15);
            $this->assertSame('15, Mar 2026', date_formating($carbon));
        } finally {
            restore_error_handler();
        }

        // A Unix timestamp resolves the same whether passed as int or string.
        $ts = Carbon::create(2026, 3, 15, 12)->timestamp;
        $this->assertSame(date_formating((string) $ts), date_formating($ts));

        // A plain date string still parses.
        $this->assertSame('15, Mar 2026', date_formating('2026-03-15'));
    }
}
