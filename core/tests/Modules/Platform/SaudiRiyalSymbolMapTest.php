<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Controllers\Admin\UserController;
use Juwri\Core\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Saudi Riyal Symbol (U+20C1) rollout — S5: the three divergent static symbol
 * maps + the seeded countries row now all point Saudi Arabia at U+20C1, while
 * Iran and Yemen keep the generic rial ligature ﷼ (locked §1).
 */
class SaudiRiyalSymbolMapTest extends TestCase
{
    use RefreshDatabase;

    private const RIYAL = "\u{20C1}";     // ⃁  SAUDI RIYAL SIGN

    private const OLD_RIAL = "\u{FDFC}";  // ﷼  generic rial ligature

    /** The new migration must move ONLY the SA row; IR + YE stay on ﷼. */
    public function test_migration_points_saudi_arabia_at_u20c1_and_leaves_iran_yemen(): void
    {
        $this->assertSame(self::RIYAL, Country::where('code', 'SA')->value('symbol'));

        // Iran + Yemen share the generic ligature and must be untouched.
        $this->assertSame(self::OLD_RIAL, Country::where('code', 'IR')->value('symbol'));
        $this->assertSame(self::OLD_RIAL, Country::where('code', 'YE')->value('symbol'));
    }

    public function test_admin_currency_symbol_for_code_returns_u20c1_for_sar(): void
    {
        $method = new ReflectionMethod(UserController::class, 'currencySymbolForCode');
        $method->setAccessible(true);
        $controller = app(UserController::class);

        $this->assertSame(self::RIYAL, $method->invoke($controller, 'SAR'));
        // AED now returns its own new sign U+20C3 (font gate cleared 2026-08-12).
        $this->assertSame("\u{20C3}", $method->invoke($controller, 'AED'));
    }

    public function test_country_factory_uses_the_new_riyal_sign(): void
    {
        $this->assertSame(self::RIYAL, Country::factory()->make()->symbol);
    }
}
