<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Models\Order;
use App\Services\OrderApiService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Juwri\Modules\Pos\Models\PrintJob;
use Juwri\Modules\Pos\Services\Printing\EscPosInspector;
use Juwri\Modules\Pos\Services\Printing\EscPosRenderer;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN follow-up #3 — close the byte-level half of physical print
 * validation.
 *
 * The paper checklist in rev/parity/runbooks/physical-print-validation.md has
 * items only a printer can confirm — that the blade cuts, that the drawer
 * solenoid fires. But WHICH BYTES the renderer emits for those, WHEN, and IN
 * WHAT ORDER is deterministic, and a wrong answer there means the physical check
 * fails no matter how good the hardware is. This pins the byte-level half, so
 * the hardware step is reduced to "does the mechanism actuate", nothing more.
 *
 * Exercised through the REAL dispatch path (settle → PrintJob → decode), not a
 * bare render call, so the profile/event wiring is covered too.
 */
class PrintPhysicalBehaviourTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png',
            'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function paidReceiptBytes(Store $r, bool $drawerOn): string
    {
        StoreSettings::set($r->id, 'print_open_drawer', $drawerOn ? '1' : '0');
        PrinterProfile::create([
            'store_id' => $r->id, 'name' => 'Receipt', 'transport' => 'webserial', 'width_mm' => 80,
            'events' => ['paid'], 'station_ids' => [], 'auto_print' => true, 'active' => true, 'copies' => 1,
        ]);

        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Tea', 'price' => 25, 'active' => '1']);
        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $menu->id, 'quantity' => 1]],
        ]);

        PosService::settle($order->fresh(), 'cash', 30.0);

        $job = PrintJob::where('event', 'paid')->firstOrFail();

        return base64_decode($job->payload);
    }

    public function test_a_paid_receipt_kicks_the_drawer_when_the_store_enables_it(): void
    {
        $bytes = $this->paidReceiptBytes($this->storeFixture(), drawerOn: true);
        $trace = new EscPosInspector()->inspect($bytes)['trace'];
        $names = array_column($trace, 'name');

        $this->assertContains('cash-drawer kick', $names, 'a paid receipt with the drawer option on must emit ESC p');
        $this->assertContains('cut', $names);
    }

    public function test_a_paid_receipt_does_not_kick_the_drawer_when_disabled(): void
    {
        $bytes = $this->paidReceiptBytes($this->storeFixture(), drawerOn: false);
        $names = array_column(new EscPosInspector()->inspect($bytes)['trace'], 'name');

        $this->assertNotContains('cash-drawer kick', $names, 'no drawer pulse when the store never enabled it');
        $this->assertContains('cut', $names, 'the receipt still cuts');
    }

    public function test_the_drawer_kick_precedes_the_cut(): void
    {
        // A printer that receives the cut first severs the ticket before the
        // drawer pulse reaches it, and the drawer never opens.
        $bytes = $this->paidReceiptBytes($this->storeFixture(), drawerOn: true);
        $trace = new EscPosInspector()->inspect($bytes)['trace'];

        $drawerAt = $this->offsetOf($trace, 'cash-drawer kick');
        $cutAt = $this->offsetOf($trace, 'cut');
        $this->assertNotNull($drawerAt);
        $this->assertNotNull($cutAt);
        $this->assertLessThan($cutAt, $drawerAt, 'the drawer kick must come before the cut');
    }

    public function test_the_cut_is_the_final_bytes_of_every_ticket(): void
    {
        // Anything after the cut prints on the next customer's slip (or a blank
        // one). GS V 0 is three bytes, so a well-terminated ticket ends exactly
        // there. Checked for the receipt and the KOT.
        $r = $this->storeFixture();
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Tea', 'price' => 25, 'active' => '1']);
        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $menu->id, 'quantity' => 1]],
        ])->fresh('items.menu');

        foreach (['receipt' => EscPosRenderer::forWidthMm(80)->renderReceipt($order),
            'kot' => EscPosRenderer::forWidthMm(80)->renderKot($order)] as $kind => $bytes) {
            $result = new EscPosInspector()->inspect($bytes);
            $this->assertSame([], $result['findings'], "{$kind} has ESC/POS findings");

            $lastCut = $this->offsetOf($result['trace'], 'cut');
            $this->assertNotNull($lastCut, "{$kind} must cut");
            // GS V 0 is 3 bytes; the ticket must end immediately after it.
            $this->assertSame(strlen($bytes), $lastCut + 3, "{$kind} emits bytes after the cut");
        }
    }

    private function offsetOf(array $trace, string $name): ?int
    {
        $offset = null;
        foreach ($trace as $t) {
            if ($t['name'] === $name) {
                $offset = $t['offset']; // last occurrence wins for the cut
            }
        }

        return $offset;
    }
}
