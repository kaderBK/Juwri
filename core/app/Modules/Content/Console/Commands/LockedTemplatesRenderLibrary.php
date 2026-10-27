<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Console\Commands;

use Juwri\Modules\Content\Models\LockedTemplate;
use Juwri\Modules\Content\Services\Builder\MergeTags;
use Juwri\Modules\Content\Services\LockedTemplates\Content;
use Juwri\Modules\Content\Services\LockedTemplates\Manifest;
use Juwri\Modules\Content\Services\LockedTemplates\Renderer;
use Illuminate\Console\Command;

/**
 * Dev tool (thumbnails): render every section-library block of a template as a
 * standalone page — the template's own page with only that block in <main> — into
 * <dir>/<block>.html, for a headless browser to screenshot.
 */
class LockedTemplatesRenderLibrary extends Command
{
    protected $signature = 'locked-templates:render-library {key} {dir}';

    protected $description = 'Render each section-library block of a locked template to a standalone HTML file (thumbnails)';

    public function handle(): int
    {
        $template = LockedTemplate::query()->where('key', (string) $this->argument('key'))->first();
        $version = $template?->currentVersion() ?? $template?->latestVersion();
        if ($version === null) {
            $this->error('Unknown template or no version.');

            return self::FAILURE;
        }
        $dir = rtrim((string) $this->argument('dir'), '/');
        if (! is_dir($dir) && ! mkdir($dir, 0775, true)) {
            $this->error("Cannot create {$dir}");

            return self::FAILURE;
        }

        // Every page section hidden (required ones — header/footer — can't be), then
        // the block as a copy.
        app()->setLocale('en');   // sample brand / UI strings in the template's language
        $hidden = array_map(fn ($s) => ['key' => (string) $s['key'], 'hidden' => true], Manifest::sections($version->manifestData()));
        $n = 0;
        foreach ($version->libraryData() as $block) {
            $key = (string) ($block['key'] ?? '');
            $content = Content::normalize(['sections' => array_merge($hidden, [['key' => $key.'~cthumb', 'hidden' => false]])]);
            $lt = Renderer::render($version, $content, ['lang' => 'en', 'tokens' => MergeTags::forPreview()]);
            $html = view('locked-templates.page', ['lt' => $lt, 'title' => $key, 'headExtra' => '<meta name="robots" content="noindex">'])->render();
            file_put_contents("{$dir}/{$key}.html", $html);
            $n++;
        }
        $this->info("{$n} blocks rendered to {$dir}");

        return self::SUCCESS;
    }
}
