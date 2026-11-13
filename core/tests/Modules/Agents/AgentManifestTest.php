<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/** Agents P4: UCP-style discovery manifest + per-restaurant llms.txt. */
class AgentManifestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private function storeFixture(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);

        return $r;
    }

    public function test_manifest_declares_capabilities(): void
    {
        $r = $this->storeFixture();
        $this->getJson(route('agent.manifest', $r->id))
            ->assertOk()
            ->assertJsonPath('capabilities.order_create', true)
            ->assertJsonPath('capabilities.checkout', 'escalation_only')
            ->assertJsonPath('payment', 'pay_on_arrival')
            ->assertJsonPath('store.id', $r->id);
    }

    public function test_manifest_leaks_no_secrets(): void
    {
        $r = $this->storeFixture();
        $json = $this->getJson(route('agent.manifest', $r->id))->assertOk()->getContent();
        $this->assertStringNotContainsStringIgnoringCase('secret', $json);
        $this->assertStringNotContainsStringIgnoringCase('password', $json);
    }

    public function test_per_restaurant_llms_lists_menu(): void
    {
        $r = $this->storeFixture();
        $res = $this->get(route('agent.llms', $r->id))->assertOk();
        $this->assertStringContainsString('text/plain', (string) $res->headers->get('Content-Type'));
        $res->assertSeeHtml($r->title)->assertSeeHtml('Pizza')->assertSeeHtml('create_order');
    }
}
