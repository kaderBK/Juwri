<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Juwri\Modules\Storefront\Storefront\ThemePackageInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * P5-4: load-test the render service and the install path. Renders the real
 * crave port and the sectioned starter against the demo dataset N times,
 * reporting p50/p95/max latency and peak memory — the numbers that tell us
 * whether the ResourceLimits budget and shared hosting can carry the load.
 * The install bench times the full validate→extract→ledger path.
 */
class PlatformLoadTest extends Command
{
    protected $signature = 'platform:load-test {--renders=100} {--installs=10}';

    protected $description = 'Benchmark the Liquid render service and the theme install path';

    public function handle(): int
    {
        $renders = max(1, (int) $this->option('renders'));
        $installs = max(1, (int) $this->option('installs'));

        // ── Render bench: the real crave port (single-file, heaviest data) ──
        $crave = MarketplaceThemeResolver::resolve('mp:crave@1', gate: false);
        if ($crave) {
            $ctx = app(ThemeContext::class);
            $vars = DemoStorefront::vars();
            $globals = $ctx->build($vars);
            $registers = $ctx->registers($vars) + ['theme_asset_map' => $crave->assetMap()];
            $renderer = new LiquidRenderer(new LiquidEnvironmentFactory(), new RenderClient());
            $source = $crave->templateSource();

            $stats = $this->bench($renders, fn () => $renderer->render($source, $globals, 'live', $registers, $crave->snippets()));
            $this->report('crave (single-file liquid)', $renders, $stats);
        } else {
            $this->warn('crave reference theme not resolvable — skipping.');
        }

        // ── Sectioned bench: the starter (index.json + sections + snippet) ──
        $starter = MarketplaceThemeResolver::resolve('mp:starter@1', gate: false);
        if ($starter) {
            $engine = app(SectionedTemplate::class);
            $ctx = app(ThemeContext::class);
            $vars = DemoStorefront::vars();
            $globals = $ctx->build($vars);

            $stats = $this->bench($renders, fn () => $engine->render($starter, $globals));
            $this->report('starter (sectioned)', $renders, $stats);
        }

        // ── Install bench: validate → extract → ledger ──
        $zip = $this->makeBenchZip();
        $times = [];
        for ($i = 0; $i < $installs; $i++) {
            $slugDir = storage_path('app/marketplace-themes/loadbench');
            File::deleteDirectory($slugDir);
            \App\Models\Theme::where('slug', 'loadbench')->delete();

            $t0 = microtime(true);
            new ThemePackageInstaller()->install($zip);
            $times[] = (microtime(true) - $t0) * 1000;
        }
        File::deleteDirectory(storage_path('app/marketplace-themes/loadbench'));
        File::deleteDirectory(public_path('assets/marketplace-themes/loadbench'));
        \App\Models\Theme::where('slug', 'loadbench')->delete();
        @unlink($zip);
        sort($times);
        $this->report('install path (validate+extract+ledger)', $installs, [
            'p50' => $times[(int) floor(count($times) * .5)],
            'p95' => $times[min(count($times) - 1, (int) floor(count($times) * .95))],
            'max' => end($times),
        ]);

        $this->info('peak memory: '.round(memory_get_peak_usage(true) / 1048576, 1).' MB');

        return self::SUCCESS;
    }

    /** @return array{p50: float, p95: float, max: float} */
    private function bench(int $n, callable $fn): array
    {
        $fn(); // warm caches (autoload, config)
        $times = [];
        for ($i = 0; $i < $n; $i++) {
            $t0 = microtime(true);
            $fn();
            $times[] = (microtime(true) - $t0) * 1000;
        }
        sort($times);

        return [
            'p50' => $times[(int) floor($n * .5)],
            'p95' => $times[min($n - 1, (int) floor($n * .95))],
            'max' => end($times),
        ];
    }

    private function report(string $label, int $n, array $s): void
    {
        $this->info(sprintf(
            '%-40s n=%-4d p50=%6.1fms  p95=%6.1fms  max=%6.1fms',
            $label, $n, $s['p50'], $s['p95'], $s['max']
        ));
    }

    private function makeBenchZip(): string
    {
        $path = storage_path('app/loadbench-theme.zip');
        @unlink($path);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('theme.json', json_encode(['name' => 'Load Bench', 'slug' => 'loadbench', 'version' => '1.0.0']));
        $zip->addFromString('templates/index.liquid', '<h1>{{ restaurant.title | escape }}</h1>');
        $zip->addFromString('assets/css/style.css', str_repeat('.x{color:red}', 500));
        $zip->close();

        return $path;
    }
}
