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
 * SI3 (#7): dietary/allergen keyword tagging command.
 */
class StudioExtractDepthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
        Storage::fake('local');
    }

    private function sessionWithDraft(array $categories): StudioSession
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'), json_encode(['categories' => $categories]));
        return $s;
    }

    private function req(string $token, array $body): Request
    {
        $r = Request::create('/studio/command', 'POST', $body);
        $r->cookies->set('studio_guest', $token);
        return $r;
    }

    public function test_tag_dietary_adds_keyword_tags(): void
    {
        $s = $this->sessionWithDraft([['name' => 'Menu', 'items' => [
            ['name' => 'Vegan Wrap', 'description' => 'spicy and fresh', 'price' => 5],
            ['name' => 'Beef Burger', 'description' => '', 'price' => 8],
        ]]]);

        $resp = new StudioController()->command($this->req($s->guest_token, ['command' => 'tag_dietary']));
        $data = $resp->getData(true);

        $tags = $data['data']['categories'][0]['items'][0]['tags'];
        $this->assertContains('vegan', $tags);
        $this->assertContains('spicy', $tags);
        $this->assertArrayNotHasKey('tags', $data['data']['categories'][0]['items'][1]); // beef -> none
    }

    public function test_price_adjust_requires_percent(): void
    {
        $s = $this->sessionWithDraft([['name' => 'Menu', 'items' => [['name' => 'X', 'price' => 5]]]]);

        $resp = new StudioController()->command($this->req($s->guest_token, ['command' => 'price_adjust']));
        $this->assertSame(422, $resp->getStatusCode());
    }
}
