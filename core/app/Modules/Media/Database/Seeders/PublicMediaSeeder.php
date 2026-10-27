<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Database\Seeders;

use Juwri\Modules\Media\Models\PublicMediaAsset;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Intervention\Image\Facades\Image;

/**
 * FEATURES-5 Track B1.4 — seed ~30 curated placeholder images so the owner
 * "Public library" tab is never empty out of the box. Images are generated
 * locally (solid gradient + label), CC0/no third-party assets. Idempotent.
 *
 * Run: php artisan db:seed --class=Juwri\\Modules\\Media\\Database\\Seeders\\PublicMediaSeeder
 */
class PublicMediaSeeder extends Seeder
{
    /** @var array<string,array<int,array{0:string,1:string}>>  category => [ [label, hexColor], ... ] */
    private array $catalog = [
        'food' => [
            ['Classic Burger', '#c0392b'], ['Wood-fired Pizza', '#e67e22'], ['Fresh Pasta', '#f1c40f'],
            ['Green Salad', '#27ae60'], ['Grilled Steak', '#7b241c'], ['Sushi Platter', '#16a085'],
            ['Tacos', '#d35400'], ['Fried Chicken', '#e59866'],
        ],
        'drink' => [
            ['Iced Coffee', '#6f4e37'], ['Fresh Juice', '#f39c12'], ['Smoothie', '#e84393'],
            ['Cocktail', '#8e44ad'], ['Green Tea', '#2ecc71'], ['Lemonade', '#f7dc6f'],
        ],
        'dessert' => [
            ['Chocolate Cake', '#5d4037'], ['Ice Cream', '#fadbd8'], ['Cheesecake', '#f5cba7'],
            ['Donuts', '#ec7063'], ['Macarons', '#bb8fce'], ['Waffles', '#d4ac0d'],
        ],
        'banner' => [
            ['Grand Opening', '#2c3e50'], ['Weekend Special', '#c0392b'], ['Happy Hour', '#8e44ad'],
            ['New Menu', '#16a085'],
        ],
        'interior' => [
            ['Cozy Cafe', '#5d6d7e'], ['Modern Dining', '#34495e'], ['Outdoor Patio', '#27ae60'],
            ['Bar Counter', '#7d6608'],
        ],
        'other' => [
            ['Chef at Work', '#2e4053'], ['Table Setting', '#a04000'],
        ],
    ];

    public function run(): void
    {
        if (PublicMediaAsset::where('source', 'curated')->count() >= 25) {
            $this->command?->info('Curated public media already seeded — skipping.');
            return;
        }

        $dir = public_path('storage/public-media');
        File::ensureDirectoryExists($dir);

        $count = 0;
        foreach ($this->catalog as $category => $items) {
            foreach ($items as [$label, $color]) {
                $filename = 'seed-' . \Illuminate\Support\Str::slug($label) . '-' . \Illuminate\Support\Str::random(6) . '.webp';

                try {
                    $img = Image::canvas(1000, 750, $color);
                    // Simple darkened footer bar + label text.
                    $img->rectangle(0, 620, 1000, 750, function ($d) {
                        $d->background('rgba(0,0,0,0.35)');
                    });
                    $img->text($label, 500, 690, function ($font) {
                        $font->size(46);
                        $font->color('#ffffff');
                        $font->align('center');
                        $font->valign('middle');
                    });
                    $img->encode('webp', 85);
                    $img->save($dir . '/' . $filename);
                } catch (\Throwable $e) {
                    $this->command?->warn("Could not generate {$label}: " . $e->getMessage());
                    continue;
                }

                PublicMediaAsset::create([
                    'filename'      => $filename,
                    'original_name' => $label . '.webp',
                    'title'         => $label,
                    'tags'          => array_values(array_filter(array_map('strtolower', explode(' ', $label)))),
                    'category'      => $category,
                    'width'         => 1000,
                    'height'        => 750,
                    'size_bytes'    => @filesize($dir . '/' . $filename) ?: null,
                    'source'        => 'curated',
                    'active'        => true,
                ]);
                $count++;
            }
        }

        $this->command?->info("Seeded {$count} curated public media images.");
    }
}
