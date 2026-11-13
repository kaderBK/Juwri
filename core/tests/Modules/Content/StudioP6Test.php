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
 * S4-P6: abuse control (rate limits), a bulk chat command, guest delete-now, i18n.
 */
class StudioP6Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
        Storage::fake('local');
    }

    private function sessionWithDraft(): StudioSession
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        Storage::disk('local')->put($s->storagePath('draft.json'),
            json_encode(['categories' => [['name' => 'Mains', 'items' => [['name' => 'Burger', 'description' => '', 'price' => 10]]]]]));
        return $s;
    }

    private function reqWithCookie(string $token, array $body = []): Request
    {
        $req = Request::create('/x', 'POST', $body);
        $req->cookies->set('studio_guest', $token);
        return $req;
    }

    public function test_price_adjust_command_bumps_all_prices(): void
    {
        $s = $this->sessionWithDraft();

        $resp = new StudioController()->command($this->reqWithCookie($s->guest_token, ['command' => 'price_adjust', 'percent' => 10]));
        $data = $resp->getData(true);

        $this->assertTrue($data['success']);
        $this->assertEquals(11.0, $data['data']['categories'][0]['items'][0]['price']);
        // Persisted to the guest draft.
        $draft = json_decode(Storage::disk('local')->get($s->storagePath('draft.json')), true);
        $this->assertEquals(11.0, $draft['categories'][0]['items'][0]['price']);
    }

    public function test_forget_purges_session_and_files(): void
    {
        $s = $this->sessionWithDraft();
        Storage::disk('local')->assertExists($s->storagePath('draft.json'));

        new StudioController()->forget($this->reqWithCookie($s->guest_token));

        $this->assertNull(StudioSession::find($s->id));
        Storage::disk('local')->assertMissing($s->storagePath('draft.json'));
    }

    public function test_ai_endpoints_are_rate_limited(): void
    {
        $routes = app('router')->getRoutes();
        $this->assertTrue(collect($routes->getByName('studio.upload')->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:20,1,')), 'a prefixed throttle:20,1 throttle');
        $this->assertTrue(collect($routes->getByName('studio.design')->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:10,1,')), 'a prefixed throttle:10,1 throttle');
    }

    public function test_studio_view_is_i18n_and_rtl_aware(): void
    {
        $src = file_get_contents(resource_path('views/studio/index.blade.php'));
        $this->assertStringContainsString('___(', $src);           // translated strings
        $this->assertStringContainsString('current_language()', $src); // dir/RTL
    }
}
