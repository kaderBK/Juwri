<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Symfony\Component\Finder\Finder;
use Tests\Support\TestPlans;
use Tests\Support\ViewRoots;
use Tests\TestCase;

/**
 * WI-15 (SPEC known risk (c)): JSON printed raw into a `<script>` must be
 * hex-escaped (@json, json_ld(), or json_encode with JSON_HEX_TAG and the
 * other HEX flags), or stored text such as `</script><script>…` closes the
 * script element.
 */
class JsonInScriptEscapingTest extends TestCase
{
    use RefreshDatabase;

    private const HEX_FLAGS = ['JSON_HEX_TAG', ', 15)'];

    public function test_no_view_prints_json_encode_raw_without_the_hex_flags(): void
    {
        $offenders = [];
        $finder = (new Finder)->files()->in([...ViewRoots::all(), base_path('../assets')])->name('*.php'); // + ee module views (P1-13)

        foreach ($finder as $file) {
            foreach (preg_split('/\R/', $file->getContents()) as $n => $line) {
                if (! preg_match('/\{!!\s*json_encode\(/', $line)) {
                    continue;
                }
                $calls = preg_split('/\{!!\s*json_encode\(/', $line);
                array_shift($calls);
                foreach ($calls as $call) {
                    $expr = strstr($call, '!!}', true) ?: $call;
                    $hexed = false;
                    foreach (self::HEX_FLAGS as $flag) {
                        $hexed = $hexed || str_contains($expr, $flag);
                    }
                    if (! $hexed) {
                        $offenders[] = ViewRoots::relative($file->getPathname()).':'.($n + 1);
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), 'Use @json(...) or json_encode(..., JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)');
    }

    public function test_pos_station_name_cannot_close_the_settings_script(): void
    {
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->shareLayoutGlobals();

        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        $payload = '</script><img src=x onerror=alert(1)>';

        $this->actingAs($owner, 'web')
            ->postJson(route('pos.settings.save', ['store' => $store->id]), [
                'auto_print' => 'both', 'copies' => 1, 'station_name' => $payload, 'autolock_minutes' => 5,
            ])->assertOk();

        $html = $this->actingAs($owner, 'web')
            ->get(route('pos.settings', ['store' => $store->id]))
            ->assertOk()->getContent();

        $escaped = trim((string) json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), '"');
        $this->assertStringNotContainsString($payload, $html);
        $this->assertStringContainsString('"station_name":"'.$escaped.'"', $html);
    }
}
