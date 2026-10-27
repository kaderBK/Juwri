<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use Juwri\Modules\Menu\Models\Menu;
use App\Models\Order;
use App\Models\OrderItem;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Pos\Services\Printing\EscPosRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * N8 — Sunmi kiosk printing: ESC/POS renderer bytes + token-authed, tenant-scoped
 * endpoint with the owner auto-print kill-switch.
 */
class SunmiKioskPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);

        // F6-4: the ESC/POS endpoint now carries the same `kiosk` gate as kiosk web
        // mode (kill veto always + entitlement when plan_gated). `kiosk` defaults to
        // OFF platform-wide, so the fixture must turn it on the way KioskWebModeTest
        // already did — otherwise these tests assert against a killed feature.
        config(['settings.admin_allow_kiosk' => true]);
        \App\Services\FeatureRegistry::clearCache();
        \App\Services\FeatureAccess::flush();
    }

    private function order(bool $autoPrint = true): array
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
        ]);
        $u = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1]);
        $r = Store::create([
            'user_id' => $u->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Bites',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png',
            'address' => '12 St', 'phone' => '0500', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($autoPrint) {
            StoreSettings::set($r->id, 'kiosk_auto_print', '1');
        }
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Burger', 'price' => 20, 'active' => '1']);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'new', 'track_token' => Str::random(32)]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2]);
        return [$r, $order->load('items.menu')];
    }

    public function test_renderer_produces_escpos_bytes(): void
    {
        [$r, $order] = $this->order();
        $bytes = new EscPosRenderer(48)->render($order);

        $this->assertStringStartsWith("\x1B@", $bytes);        // ESC @ init
        $this->assertStringContainsString('Bites', $bytes);     // restaurant header
        $this->assertStringContainsString('2x Burger', $bytes); // line item
        $this->assertStringContainsString('40.00', $bytes);     // 2 × 20
        $this->assertStringContainsString("\x1DV0", $bytes);   // cut command
    }

    public function test_endpoint_returns_base64_stream(): void
    {
        [$r, $order] = $this->order();
        $res = $this->getJson(route('api.kiosk.escpos', $order->track_token))->assertOk();
        $b64 = $res->json('escpos');
        $this->assertNotEmpty($b64);
        $this->assertStringStartsWith("\x1B@", base64_decode($b64));
    }

    public function test_endpoint_204_when_auto_print_disabled(): void
    {
        [$r, $order] = $this->order(autoPrint: false);
        $this->getJson(route('api.kiosk.escpos', $order->track_token))->assertNoContent();
    }

    public function test_unknown_token_is_404(): void
    {
        $this->getJson(route('api.kiosk.escpos', 'nope-'.Str::random(28)))->assertNotFound();
    }

    public function test_drawer_kick_flag(): void
    {
        [$r, $order] = $this->order();
        $bytes = new EscPosRenderer(48)->render($order, true);
        $this->assertStringContainsString("\x1Bp0", $bytes); // drawer kick pulse
    }

    public function test_sunmi_kiosk_doc_renders(): void
    {
        $this->get(route('integrations.docs', 'sunmi-kiosk'))->assertOk()->assertSeeHtml('Sunmi');
    }
}
