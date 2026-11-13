<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use Juwri\Modules\Ai\Models\TranslationMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(1, 2); // P1-04 5b: fixture store ids must be real stores
    }

    public function test_remember_then_lookup(): void
    {
        TranslationMemory::remember(1, 'ar', 'Chicken', 'دجاج');
        $this->assertSame('دجاج', TranslationMemory::lookup(1, 'ar', 'Chicken'));
        $this->assertNull(TranslationMemory::lookup(1, 'fr', 'Chicken'));   // wrong lang
        $this->assertNull(TranslationMemory::lookup(2, 'ar', 'Chicken'));   // wrong restaurant
        $this->assertNull(TranslationMemory::lookup(1, 'ar', 'Beef'));      // miss
    }

    public function test_remember_is_idempotent_and_updates(): void
    {
        TranslationMemory::remember(1, 'ar', 'Chicken', 'دجاج');
        TranslationMemory::remember(1, 'ar', 'Chicken', 'دجاجة'); // refine
        $this->assertSame(1, TranslationMemory::where('store_id', 1)->where('lang', 'ar')->count());
        $this->assertSame('دجاجة', TranslationMemory::lookup(1, 'ar', 'Chicken'));
    }

    public function test_ignores_empty(): void
    {
        TranslationMemory::remember(1, 'ar', '  ', 'x');
        TranslationMemory::remember(1, 'ar', 'x', '  ');
        $this->assertSame(0, TranslationMemory::count());
    }
}
