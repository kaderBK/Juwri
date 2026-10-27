<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Database\Seeders;

use App\Models\App;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Intervention\Image\Facades\Image;

/**
 * stores P2-1: platform-owned demo apps for the public App Store, one per
 * category, spanning all three billing types. Idempotent by slug. Standalone
 * (demo data is opt-in): php artisan db:seed --class=AppStoreSeeder
 */
class AppStoreSeeder extends Seeder
{
    public function run(): void
    {
        $defs = [
            ['slug' => 'quick-order',  'name' => 'QuickOrder',   'category' => 'ordering',    'billing' => 'free',      'tagline' => 'One-tap reordering for your regulars.',        'color' => '#3f7fb5', 'scopes' => ['menu:read', 'orders:write']],
            ['slug' => 'blast-mail',   'name' => 'BlastMail',     'category' => 'marketing',   'billing' => 'recurring', 'price' => 9,  'tagline' => 'Email campaigns from your menu data.',   'color' => '#b5713f', 'scopes' => ['customers:read', 'orders:read']],
            ['slug' => 'route-runner', 'name' => 'RouteRunner',   'category' => 'delivery',    'billing' => 'usage', 'usage_unit' => 'delivery', 'usage_price' => 0.15, 'tagline' => 'Live driver dispatch and tracking.', 'color' => '#4c8a4c', 'scopes' => ['orders:read', 'orders:write']],
            ['slug' => 'peak-insights','name' => 'PeakInsights',  'category' => 'analytics',   'billing' => 'recurring', 'price' => 19, 'tagline' => 'Sales and menu-engineering dashboards.', 'color' => '#6f5ab5', 'scopes' => ['orders:read', 'menu:read']],
            ['slug' => 'loyal-loop',   'name' => 'LoyalLoop',     'category' => 'loyalty',     'billing' => 'free',      'tagline' => 'Points, rewards and win-backs.',         'color' => '#c9932f', 'scopes' => ['customers:read', 'customers:write']],
            ['slug' => 'book-keeper',  'name' => 'BookKeeper',    'category' => 'back_office', 'billing' => 'recurring', 'price' => 12, 'tagline' => 'Sync orders to your accounting.',        'color' => '#5a7a8a', 'scopes' => ['orders:read']],
            ['slug' => 'pos-bridge',   'name' => 'POS Bridge',    'category' => 'pos',         'billing' => 'recurring', 'price' => 15, 'tagline' => 'Two-way sync with your POS.',            'color' => '#b34c4c', 'scopes' => ['menu:read', 'menu:write', 'orders:read']],
            ['slug' => 'zap-connect',  'name' => 'ZapConnect',    'category' => 'integrations','billing' => 'usage', 'usage_unit' => 'task', 'usage_price' => 0.02, 'tagline' => 'Automate with thousands of apps.', 'color' => '#8a5ab5', 'scopes' => ['orders:read', 'menu:read']],
        ];

        foreach ($defs as $d) {
            $app = App::updateOrCreate(
                ['slug' => $d['slug']],
                [
                    'name'                => $d['name'],
                    'developer_user_id'   => null,               // platform-owned
                    'description'         => $d['tagline'] . ' Built and maintained by the platform.',
                    'scopes'              => $d['scopes'],
                    'webhook_events'      => ['order.paid'],
                    'status'              => 'approved',
                    'billing_type'        => $d['billing'],
                    'price'               => $d['price'] ?? 0,
                    'billing_period_days' => 30,
                    'usage_unit'          => $d['usage_unit'] ?? null,
                    'usage_price'         => $d['usage_price'] ?? null,
                    'category'            => $d['category'],
                    'tagline'             => $d['tagline'],
                    'support_url'         => 'https://help.juwri.test/apps/' . $d['slug'],
                    'docs_url'            => 'https://developers.juwri.test/apps/' . $d['slug'],
                ]
            );

            if ($app->screenshots()->exists()) {
                continue;
            }

            $dir = public_path("storage/marketplace/app/{$app->id}/");
            File::ensureDirectoryExists($dir);
            $file = "seed-{$app->id}.webp";

            $img = Image::canvas(1280, 800, '#f4efe6');
            $img->rectangle(0, 0, 1280, 130, fn ($draw) => $draw->background($d['color']));
            $img->rectangle(80, 210, 1200, 320, fn ($draw) => $draw->background('#ffffff'));
            $img->rectangle(80, 360, 780, 720, fn ($draw) => $draw->background('#ffffff'));
            $img->encode('webp', 85)->save($dir . $file);

            $app->media()->create([
                'disk_path' => "storage/marketplace/app/{$app->id}/{$file}",
                'position'  => 0,
                'kind'      => 'screenshot',
            ]);
        }

        $this->command?->info('Seeded ' . count($defs) . ' approved apps with screenshots.');
    }
}
