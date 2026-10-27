<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Models\Store;
use Juwri\Modules\Media\Contracts\Images;
use Juwri\Modules\Menu\Models\Menu;

class ConvertToWebp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'storage:convert-to-webp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Convert existing menu and restaurant images in the database to WebP format';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting WebP conversion for legacy images...');

        // 1. Convert Menu images
        $this->info('Processing Menu item main images...');
        Menu::chunk(100, function ($menus) {
            foreach ($menus as $menu) {
                if (! empty($menu->image) && ! str_ends_with(strtolower($menu->image), '.webp')) {
                    $newFile = app(Images::class)->convertToWebp('storage/menu/', $menu->image, $menu->store_id);
                    if ($newFile) {
                        $menu->update(['image' => $newFile]);
                        $this->line("Menu #{$menu->id} image converted: {$newFile}");
                    }
                }

                // Gallery images
                if (is_array($menu->gallery_images) && count($menu->gallery_images) > 0) {
                    $updatedGallery = [];
                    $changed = false;
                    foreach ($menu->gallery_images as $gImg) {
                        if (! empty($gImg) && ! str_ends_with(strtolower($gImg), '.webp')) {
                            $newGFile = app(Images::class)->convertToWebp('storage/menu/', $gImg, $menu->store_id);
                            if ($newGFile) {
                                $updatedGallery[] = $newGFile;
                                $changed = true;
                            } else {
                                $updatedGallery[] = $gImg;
                            }
                        } else {
                            $updatedGallery[] = $gImg;
                        }
                    }
                    if ($changed) {
                        $menu->update(['gallery_images' => $updatedGallery]);
                        $this->line("Menu #{$menu->id} gallery images updated.");
                    }
                }
            }
        });

        // 2. Convert Restaurant / Post images
        $this->info('Processing Restaurant main/cover/bio images...');
        Store::chunk(100, function ($stores) {
            foreach ($stores as $store) {
                // main_image (logo)
                if (! empty($store->main_image) && ! str_ends_with(strtolower($store->main_image), '.webp')) {
                    $newFile = app(Images::class)->convertToWebp('storage/store/logo/', $store->main_image, $store->id);
                    if ($newFile) {
                        $store->update(['main_image' => $newFile]);
                        $this->line("Post #{$store->id} main_image converted: {$newFile}");
                    }
                }

                // cover_image
                if (! empty($store->cover_image) && ! str_ends_with(strtolower($store->cover_image), '.webp')) {
                    $newFile = app(Images::class)->convertToWebp('storage/store/cover/', $store->cover_image, $store->id);
                    if ($newFile) {
                        $store->update(['cover_image' => $newFile]);
                        $this->line("Post #{$store->id} cover_image converted: {$newFile}");
                    }
                }

                // bio_main_image
                if (! empty($store->bio_main_image) && ! str_ends_with(strtolower($store->bio_main_image), '.webp')) {
                    $newFile = app(Images::class)->convertToWebp('storage/store/logo/', $store->bio_main_image, $store->id);
                    if ($newFile) {
                        $store->update(['bio_main_image' => $newFile]);
                        $this->line("Post #{$store->id} bio_main_image converted: {$newFile}");
                    }
                }

                // bio_cover_image
                if (! empty($store->bio_cover_image) && ! str_ends_with(strtolower($store->bio_cover_image), '.webp')) {
                    $newFile = app(Images::class)->convertToWebp('storage/store/cover/', $store->bio_cover_image, $store->id);
                    if ($newFile) {
                        $store->update(['bio_cover_image' => $newFile]);
                        $this->line("Post #{$store->id} bio_cover_image converted: {$newFile}");
                    }
                }
            }
        });

        $this->info('All WebP conversions completed!');

        return self::SUCCESS;
    }
}
