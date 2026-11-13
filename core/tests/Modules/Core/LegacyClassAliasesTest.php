<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Juwri\Core\Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\LegacyClassAliases;
use Tests\TestCase;

/**
 * P1-08a: classes moved into app/Core and app/Modules keep working under their old names
 * (serialized payloads, stored class names) and Eloquent models keep their old name as the
 * morph type, so polymorphic *_type columns keep their stored values.
 */
class LegacyClassAliasesTest extends TestCase
{
    use RefreshDatabase;

    /** An old (pre-P1-08) class name, built so the move tool's rewrite never touches it. */
    private static function old(string $rest): string
    {
        return 'App'.'\\'.$rest;
    }

    public function test_old_class_names_resolve_to_the_moved_classes(): void
    {
        $this->assertTrue(class_exists(self::old('Models\\User')));
        $this->assertSame(User::class, get_class(new (self::old('Models\\User'))));
        // is_a()/instanceof never autoload: the alias exists once the old name was asked for
        $this->assertTrue(class_exists(self::old('Models\\Post')));
        $this->assertTrue(is_a(new Store, self::old('Models\\Post'))); // renamed: Post -> Store
        $this->assertFalse(is_a(new User, self::old('Models\\Post')));
        $this->assertSame(Store::class, LegacyClassAliases::aliases()[self::old('Models\\Post')]);
        $this->assertSame(self::old('Models\\Post'), LegacyClassAliases::oldNameOf(Store::class));
        $this->assertNull(LegacyClassAliases::oldNameOf(self::old('Models\\Order')));
    }

    public function test_every_alias_target_exists_and_the_old_name_is_gone(): void
    {
        foreach (LegacyClassAliases::aliases() as $old => $new) {
            $this->assertTrue(class_exists($new) || interface_exists($new) || trait_exists($new) || enum_exists($new), "$old -> $new: target missing");
            if (str_starts_with($old, self::old(''))) {
                $this->assertFileDoesNotExist(base_path('app/'.str_replace('\\', '/', substr($old, 4)).'.php'), "$old still has a file");
            }
        }
    }

    public function test_moved_models_keep_their_old_name_as_morph_type(): void
    {
        $this->assertSame(self::old('Models\\User'), (new User)->getMorphClass());
        $this->assertSame(self::old('Models\\Post'), (new Store)->getMorphClass());
        $this->assertSame(self::old('Models\\Branch'), (new Branch)->getMorphClass());
        $this->assertSame(User::class, Relation::getMorphedModel(self::old('Models\\User')));
        $this->assertSame(Store::class, Relation::getMorphedModel(self::old('Models\\Post')));
    }

    public function test_activity_log_stores_the_stable_subject_type(): void
    {
        $user = User::factory()->create();
        $store = Store::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);

        log_activity('store.test', $store, [], $store->id);

        $row = DB::table('activity_logs')->where('action', 'store.test')->first();
        $this->assertNotNull($row);
        $this->assertSame(self::old('Models\\Post'), $row->subject_type);
    }

    public function test_a_moved_model_finds_its_factory_by_its_old_name(): void
    {
        $this->assertInstanceOf(StoreFactory::class, Store::factory());
        $this->assertInstanceOf(Store::class, Store::factory()->make());
    }
}
