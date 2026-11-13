<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Settings\StoreSettings;

/**
 * J52 (Opus review B1): two store settings changed their path inside `stores.settings` in the restaurant -> store
 * rename, so values saved under the old paths would read as unset:
 *  - core.restaurant_template -> core.store_template  (legacy name restaurant_template -> store_template)
 *  - restaurant.color         -> store.color          (legacy name restaurant_color unchanged)
 *
 * A value moves only when the new path is empty; the old path is then removed either way, and an emptied group goes
 * with it. The JSON is decoded and encoded in PHP (chunked), so the same code runs on SQLite, MySQL and PostgreSQL.
 * Re-run safe; down() moves the values back the same way.
 */
return new class extends Migration
{
    /** @var array<string, string> old "group.key" => new "group.key" */
    private const MOVES = [
        'core.restaurant_template' => 'core.store_template',
        'restaurant.color' => 'store.color',
    ];

    public function up(): void
    {
        $this->rewrite(self::MOVES);
    }

    public function down(): void
    {
        $this->rewrite(array_flip(self::MOVES));
    }

    /** @param  array<string, string>  $moves */
    private function rewrite(array $moves): void
    {
        if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'settings')) {
            return;
        }

        DB::table('stores')->whereNotNull('settings')->select(['id', 'settings'])->orderBy('id')
            ->chunkById(200, function ($rows) use ($moves): void {
                foreach ($rows as $row) {
                    $settings = json_decode((string) $row->settings, true);
                    if (! is_array($settings)) {
                        continue;
                    }
                    $next = $this->move($settings, $moves);
                    if ($next !== $settings) {
                        DB::table('stores')->where('id', $row->id)->update([
                            'settings' => $next === [] ? null : json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                        ]);
                    }
                }
            });

        StoreSettings::flush();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $moves
     * @return array<string, mixed>
     */
    private function move(array $settings, array $moves): array
    {
        foreach ($moves as $from => $to) {
            [$fromGroup, $fromKey] = explode('.', $from, 2);
            [$toGroup, $toKey] = explode('.', $to, 2);
            if (! is_array($settings[$fromGroup] ?? null) || ! array_key_exists($fromKey, $settings[$fromGroup])) {
                continue;
            }
            $value = $settings[$fromGroup][$fromKey];
            unset($settings[$fromGroup][$fromKey]);
            if ($settings[$fromGroup] === []) {
                unset($settings[$fromGroup]);
            }
            if (! is_array($settings[$toGroup] ?? null)) {
                $settings[$toGroup] = [];
            }
            if (($settings[$toGroup][$toKey] ?? null) === null) {
                $settings[$toGroup][$toKey] = $value;
            }
        }

        return $settings;
    }
};
