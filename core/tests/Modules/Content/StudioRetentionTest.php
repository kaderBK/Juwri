<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Http\Controllers\Studio\StudioController;
use Juwri\Modules\Content\Models\StudioSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SI10 (#70): draft version history — one-level rollback after an autosave.
 */
class StudioRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
        Storage::fake('local');
    }

    private function ctrl(): StudioController
    {
        return new StudioController();
    }

    private function req(StudioSession $s, string $path, array $body = []): Request
    {
        $r = Request::create($path, 'POST', $body);
        $r->cookies->set('studio_guest', $s->guest_token);
        return $r;
    }

    public function test_rollback_restores_previous_draft(): void
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        $c = $this->ctrl();

        $c->saveDraft($this->req($s, '/studio/save-draft', ['categories' => [['name' => 'V1', 'items' => [['name' => 'Alpha', 'price' => 1]]]]]));
        $c->saveDraft($this->req($s, '/studio/save-draft', ['categories' => [['name' => 'V2', 'items' => [['name' => 'Beta', 'price' => 2]]]]]));

        // Current is V2.
        $cur = json_decode(Storage::disk('local')->get($s->storagePath('draft.json')), true);
        $this->assertSame('V2', $cur['categories'][0]['name']);

        // Roll back -> V1.
        $resp = $c->rollbackDraft($this->req($s, '/studio/rollback'));
        $this->assertSame('V1', $resp->getData(true)['data']['categories'][0]['name']);
        $restored = json_decode(Storage::disk('local')->get($s->storagePath('draft.json')), true);
        $this->assertSame('V1', $restored['categories'][0]['name']);
    }

    public function test_rollback_without_history_is_rejected(): void
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        $resp = $this->ctrl()->rollbackDraft($this->req($s, '/studio/rollback'));
        $this->assertSame(400, $resp->getStatusCode());
    }
}
