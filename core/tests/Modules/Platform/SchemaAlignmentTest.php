<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P10-3: Laravel 11 executes ->change() natively (doctrine/dbal removed) and —
 * unlike dbal — DROPS any column modifier not re-specified in the change call.
 * The app's two ->change() migrations (orders.status enum→string, menu.description
 * string→text) re-specify default/nullable; this guards the resulting schema on
 * a fresh migrate so a future edit can't silently lose a modifier.
 */
class SchemaAlignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_status_is_string_with_pending_default_not_nullable(): void
    {
        $col = collect(Schema::getColumns('orders'))->firstWhere('name', 'status');

        $this->assertNotNull($col, 'orders.status exists');
        $this->assertMatchesRegularExpression('/varchar|character varying/', strtolower($col['type']), 'enum→string conversion held');
        $this->assertFalse((bool) $col['nullable'], 'status stays NOT NULL');
        $this->assertStringContainsString('pending', strtolower((string) $col['default']), "default 'pending' held");
    }

    public function test_menu_description_is_nullable_text(): void
    {
        $col = collect(Schema::getColumns('items'))->firstWhere('name', 'description');

        $this->assertNotNull($col, 'menu.description exists');
        $this->assertStringContainsString('text', strtolower($col['type']), 'string→text widening held');
        $this->assertTrue((bool) $col['nullable'], 'description stays nullable');
    }
}
