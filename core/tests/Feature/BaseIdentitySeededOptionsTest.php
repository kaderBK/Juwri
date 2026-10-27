<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * WI-04 follow-up (WI-08g): a fresh install — `migrate --seed` on an empty
 * database — stores no base-product identity in its settings, and the
 * visible site title defaults to juwri (JUWRI LTD owns the name).
 * Complements BaseIdentityTest, which scans the source files.
 */
class BaseIdentitySeededOptionsTest extends TestCase
{
    use RefreshDatabase;

    /** Run DatabaseSeeder after the migrations, like `php artisan migrate --seed`. */
    protected bool $seed = true;

    // Built at run time so this file does not itself spell the names it forbids.
    private const PATTERN = '/quick'.'[\s_-]?qr|quick'.'[\s_-]?cms|by'.'lancer|code[\s_-]?canyon|envato/i';

    public function test_seeded_options_hold_no_base_identity(): void
    {
        $rows = DB::table('platform_settings')->get(['name as option_name', 'value as option_value']);
        $this->assertNotEmpty($rows, 'the platform_settings table is seeded by the migrations');

        $hits = $rows
            ->filter(fn ($row) => preg_match(self::PATTERN, (string) $row->option_name.' '.(string) $row->option_value) === 1)
            ->map(fn ($row) => $row->option_name.' = '.mb_substr((string) $row->option_value, 0, 120))
            ->values()
            ->all();

        $this->assertSame([], $hits, "Base product identity in seeded options:\n".implode("\n", $hits));
    }

    public function test_the_guard_pattern_catches_a_planted_forbidden_word(): void
    {
        $row = 'site_name = Quick'.'QR demo';
        $this->assertSame(1, preg_match(self::PATTERN, $row));
        $this->assertSame(0, preg_match(self::PATTERN, 'site_name = juwri'));
    }

    public function test_default_site_title_is_juwri(): void
    {
        $this->assertSame('juwri', PlatformSettings::stored('site_title'));
    }
}
