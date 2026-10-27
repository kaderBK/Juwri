<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\TestCase;

/**
 * WI-G5 (perf): the menu editor rendered a partial per item + per category, each
 * repeating the SAME store_options('default_language') read — an N+1 storm. It is
 * now memoized per request (bound to the app instance, flushed on write).
 */
class MenuEditorPerfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        StoreOption::flushOptionMemo();
    }

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id, 'title' => 'Perf', 'slug' => 'perf-store',
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
    }

    public function test_post_option_read_is_memoized_and_flushed_on_write(): void
    {
        $r = $this->store();
        StoreSettings::set($r->id, 'default_language', 'en');

        DB::enableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('en', store_options($r->id, 'default_language'));
        }
        $this->assertCount(1, DB::getQueryLog(), 'five identical reads must hit the DB once');

        // A write must invalidate the memo.
        StoreOption::updateStoreOption($r->id, 'default_language', 'ar');
        DB::flushQueryLog();
        $this->assertSame('ar', store_options($r->id, 'default_language'));
        $this->assertGreaterThanOrEqual(1, count(DB::getQueryLog()), 'read after write must re-query');
        DB::disableQueryLog();
    }
}
