<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Juwri\Modules\Content\Services\Studio\HeadlessChrome;
use Juwri\Modules\Content\Services\Studio\StudioTemplateService;

/**
 * studio-templates P5-2: (re)generate every template package's gallery thumbnail
 * from a bundled sample menu. Run after adding or restyling a package:
 *
 *   php artisan studio:template-thumbs [--only=slug]
 */
class StudioTemplateThumbs extends Command
{
    protected $signature = 'studio:template-thumbs {--only= : one slug instead of all}';

    protected $description = 'Render gallery thumb.png for every studio template package';

    public function handle(StudioTemplateService $templates, HeadlessChrome $chrome): int
    {
        $sample = ['categories' => [
            ['name' => 'Starters', 'items' => [
                ['name' => 'Hummus with warm pita', 'description' => 'Creamy chickpeas, lemon, olive oil', 'price' => 19, 'variants' => [], 'extras' => [], 'tags' => ['vegan']],
                ['name' => 'Halloumi fries', 'description' => 'Hot honey, black sesame', 'price' => 24, 'variants' => [], 'extras' => []],
                ['name' => 'Lentil soup', 'description' => '', 'price' => 15, 'variants' => [], 'extras' => []],
            ]],
            ['name' => 'Mains', 'items' => [
                ['name' => 'Chicken shawarma plate', 'description' => 'Garlic sauce, pickles, saffron rice', 'price' => 38, 'variants' => [], 'extras' => []],
                ['name' => 'Lamb kofta', 'description' => 'Char-grilled, smoked tomato', 'price' => 52, 'variants' => [], 'extras' => []],
                ['name' => 'Grilled sea bass', 'description' => 'Herb butter, charred lemon', 'price' => 64, 'variants' => [], 'extras' => []],
            ]],
            ['name' => 'Desserts', 'items' => [
                ['name' => 'Kunafa', 'description' => 'Pistachio, orange blossom', 'price' => 26, 'variants' => [], 'extras' => []],
                ['name' => 'Dark chocolate tart', 'description' => '', 'price' => 22, 'variants' => [], 'extras' => []],
            ]],
        ]];

        $only = $this->option('only');
        $done = 0;
        foreach ($templates->all() as $slug => $manifest) {
            if ($only && $slug !== $only) {
                continue;
            }
            $html = $templates->renderWithDraft($sample, $slug, [
                'slots' => ['store_name' => 'Casa Verde', 'tagline' => 'Est. 1998'],
            ]);

            // A4 @96dpi, the .sheet element only (J18: HeadlessChrome replaces Browsershot).
            File::put($manifest['path'].'/thumb.png', $chrome->screenshot($html, 794, 1123, 1.0, '.sheet'));

            $this->info("thumb: $slug");
            $done++;
        }

        $this->info("$done thumbnail(s) rendered.");

        return self::SUCCESS;
    }
}
