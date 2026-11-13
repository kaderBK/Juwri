<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Tests\Support\ThemeConformance;
use Tests\TestCase;

/**
 * P3-04: the kit's conformance gates (THEME-CONTRACT §11 checks 1, 2, 3, 6-static and the size budgets) run on every
 * kit prototype page rendered through a Blade stub. REPORT mode (default, modes.json) prints failures and stays green;
 * ENFORCE mode (kit themes ported to Blade; flipped at P3-05) fails the test. Premium themes stay report-only (owner Q7).
 */
class ThemeConformanceTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        if (ThemeConformance::python() === null) {
            $this->markTestSkipped('python3 is not installed on this machine (the conformance gates are Python 3 stdlib).');
        }
        $this->out = ThemeConformance::outDir();
    }

    protected function tearDown(): void
    {
        if (getenv('THEME_CONFORMANCE_OUT') === false || getenv('THEME_CONFORMANCE_OUT') === '') {
            ThemeConformance::rrmdir($this->out);
        }
        parent::tearDown();
    }

    public function test_the_gates_fire_on_their_bad_samples_and_stay_quiet_on_the_good_ones(): void
    {
        $r = ThemeConformance::runGates((string) ThemeConformance::python(), $this->out, null);

        $this->assertSame(0, $r['code'], $r['output']);
        $this->assertStringContainsString('SELF-TEST OK', $r['output']);
    }

    public function test_every_kit_theme_page_renders_through_blade_unchanged_and_passes_the_gates_per_the_mode(): void
    {
        $modes = ThemeConformance::modes();
        $render = ThemeConformance::renderAll($this->out);

        $this->assertGreaterThan(100, $render['pages'], 'the prototype pages were not found');
        $this->assertSame([], $render['mismatches'], 'Blade changed these prototype pages');

        $report = [];
        $blocking = [];
        foreach ([...$modes['kit_themes'], ...$modes['premium_themes']] as $theme) {
            $r = ThemeConformance::runGates((string) ThemeConformance::python(), $this->out, $theme);
            $mode = ThemeConformance::modeFor($theme, $modes);
            $report[$theme] = ['mode' => $mode, 'passed' => $r['code'] === 0, 'output' => $r['output']];
            if (ThemeConformance::blocks($theme, $r['code'], $modes)) {
                $blocking[] = "== {$theme} ({$mode}) ==\n".$r['output'];
            } elseif ($r['code'] !== 0) {
                fwrite(STDERR, "\n[theme conformance REPORT-ONLY] {$theme} failed:\n".$r['output']."\n");
            }
        }
        file_put_contents($this->out.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->assertSame([], $blocking, implode("\n", $blocking));
        $this->assertSame(count($modes['kit_themes']) + count($modes['premium_themes']), count($report));
    }

    public function test_a_violation_in_a_rendered_page_fails_the_gates(): void
    {
        $render = ThemeConformance::renderAll($this->out);
        $this->assertSame([], $render['mismatches']);

        $page = $this->out.'/prototypes/table/en/home.html';
        $html = (string) file_get_contents($page);
        $this->assertStringContainsString('</body>', $html);
        file_put_contents($page, str_replace('</body>', '<button onclick="x()" style="color:#123456">x</button><script>alert(1)</script></body>', $html));

        $r = ThemeConformance::runGates((string) ThemeConformance::python(), $this->out, 'table');

        $this->assertNotSame(0, $r['code'], 'the gates must reject an inline handler, style attribute and inline script');
        $this->assertStringContainsString('inline-handler', $r['output']);
        $this->assertStringContainsString('style-attr', $r['output']);
    }

    public function test_the_report_enforce_switch(): void
    {
        $modes = ['kit_mode' => 'report', 'premium_mode' => 'report', 'kit_themes' => ['table'], 'premium_themes' => ['carely']];
        $this->assertFalse(ThemeConformance::blocks('table', 1, $modes), 'REPORT never blocks');

        $enforce = ['kit_mode' => 'enforce'] + $modes;
        $this->assertTrue(ThemeConformance::blocks('table', 1, $enforce), 'ENFORCE blocks a failing kit theme');
        $this->assertFalse(ThemeConformance::blocks('table', 0, $enforce));
        $this->assertFalse(ThemeConformance::blocks('carely', 1, $enforce), 'premium stays report-only');
        $this->assertFalse(ThemeConformance::blocks('some-unknown-theme', 1, $enforce), 'unknown themes are report-only');

        $shipped = ThemeConformance::modes();
        $this->assertSame('enforce', $shipped['kit_mode'], 'P3-05 flipped the kit themes to ENFORCE');
        $this->assertSame('report', $shipped['premium_mode'], 'premium themes stay report-only (owner Q7)');
        $this->assertSame('enforce', ThemeConformance::modeFor('table', $shipped), 'table (ported to Blade) is enforced');
        $this->assertSame('enforce', ThemeConformance::modeFor('brew', $shipped), 'brew (ported to Blade, P3-07) is enforced');
        $this->assertSame('enforce', ThemeConformance::modeFor('aisle', $shipped), 'aisle (ported to Blade, P3-08) is enforced');
        $this->assertSame('enforce', ThemeConformance::modeFor('basket', $shipped), 'basket (ported to Blade, P3-09) is enforced');
        $this->assertSame([], $shipped['report_until_ported'] ?? [], 'every kit theme is ported');
        $this->assertSame('enforce', ThemeConformance::modeFor('book', $shipped), 'book (P3-11, ported to Blade) is enforced');
        $this->assertSame('report', ThemeConformance::modeFor('table', ['report_until_ported' => ['table']] + $shipped), 'the report_until_ported list wins over kit_mode');
    }

    /** The policy the browser job serves pages under may only be stricter than core's storefront policy, never looser. */
    public function test_the_browser_job_csp_is_no_looser_than_the_storefront_policy(): void
    {
        $job = json_decode((string) file_get_contents(ThemeConformance::toolsDir().'/csp-policy.json'), true, 512, JSON_THROW_ON_ERROR)['directives'];

        $response = new \Illuminate\Http\Response('');
        (new \App\Support\Csp\StorefrontPolicy)->applyTo($response);
        $core = [];
        foreach (explode(';', (string) $response->headers->get('Content-Security-Policy')) as $part) {
            $tokens = preg_split('/\s+/', trim($part)) ?: [];
            $name = array_shift($tokens);
            if ($name !== null && $name !== '') {
                $core[$name] = array_values(array_filter($tokens, fn ($t) => ! str_starts_with($t, "'nonce-")));
            }
        }

        foreach ($job as $directive => $value) {
            $this->assertArrayHasKey($directive, $core, "core has no {$directive}");
            if ($directive === 'style-src-attr') {
                $this->assertSame("'none'", $value, 'the kit target: no inline style attributes');

                continue;
            }
            $this->assertSame([], array_values(array_diff(preg_split('/\s+/', $value), $core[$directive])), "{$directive} is looser than core");
        }
    }
}
