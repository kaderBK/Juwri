<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use Juwri\Modules\Content\Models\DocsEvent;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DOCS-PLATFORM D6-2: docs signals persist to docs_events and drive the
 * docs:analytics report + deflection estimate.
 */
class DocsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    public function test_feedback_and_search_persist_docs_events(): void
    {
        $this->postJson('/docs/feedback', ['pillar' => 'users', 'slug' => 'coupons', 'helpful' => false, 'comment' => 'unclear'])->assertOk();
        $this->get('/help-center/search?q=zzzznotawordzzzz')->assertOk();     // zero-result
        $this->get('/help-center/search?q=order')->assertOk();                 // has results

        $this->assertDatabaseHas('docs_events', ['type' => 'unhelpful', 'slug' => 'coupons']);
        $this->assertDatabaseHas('docs_events', ['type' => 'search_zero']);
        $this->assertDatabaseHas('docs_events', ['type' => 'search']);
    }

    public function test_analytics_command_reports_and_estimates_deflection(): void
    {
        $this->requiresEditionPackage('a table only an edition package creates');
        $u1 = User::factory()->create();
        $u2 = User::factory()->create();

        // Two signed-in docs users; u2 opens a ticket within 72h → not deflected.
        DocsEvent::create(['type' => 'unhelpful', 'pillar' => 'users', 'slug' => 'pos', 'user_id' => $u1->id, 'created_at' => now()->subDay()]);
        DocsEvent::create(['type' => 'search_zero', 'pillar' => 'users', 'meta' => 'refund a split bill', 'user_id' => $u2->id, 'created_at' => now()->subDay()]);
        DB::table('support_tickets')->insert([
            'user_id' => $u2->id, 'subject' => 'help', 'category' => 'general', 'priority' => 'normal',
            'status' => 'open', 'created_at' => now()->subDay()->addHour(), 'updated_at' => now(),
        ]);

        $this->artisan('docs:analytics --days=30')
            ->assertExitCode(0)
            ->expectsOutputToContain('Failing pages')
            ->expectsOutputToContain('zero-result')
            ->expectsOutputToContain('Deflection');
    }
}
