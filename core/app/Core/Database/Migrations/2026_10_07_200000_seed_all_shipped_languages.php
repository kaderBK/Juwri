<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P4-13: a fresh install shipped only the en and fr language rows, so Arabic (a main market) and the other
 * locales the product has translations for (lang/<code>/) could not be chosen on a Community install.
 * Inserts the missing rows for every shipped locale; an existing row (any edit the admin made to it, its active flag included)
 * is never touched. The new rows are INACTIVE: a fresh install keeps en and fr active exactly as before (storefront language
 * switcher, SEO hreflang, theme view models unchanged) and the admin switches a language on with one click.
 */
return new class extends Migration
{
    /** code => [name, direction] */
    private const SHIPPED = [
        'ar' => ['Arabic', 'rtl'],
        'de' => ['German', 'ltr'],
        'es' => ['Spanish', 'ltr'],
        'hi' => ['Hindi', 'ltr'],
        'pt' => ['Portuguese', 'ltr'],
        'ru' => ['Russian', 'ltr'],
        'tr' => ['Turkish', 'ltr'],
        'ur' => ['Urdu', 'rtl'],
        'zh' => ['Chinese', 'ltr'],
    ];

    public function up(): void
    {
        $position = (int) (DB::table('languages')->max('position') ?? 1);
        foreach (self::SHIPPED as $code => [$name, $direction]) {
            if (DB::table('languages')->where('code', $code)->exists()) {
                continue;
            }
            DB::table('languages')->insert([
                'code' => $code, 'name' => $name, 'direction' => $direction, 'active' => 0, 'position' => ++$position,
            ]);
        }
    }

    public function down(): void
    {
        // Rows an admin may already depend on (stores, translations) are left in place.
    }
};
