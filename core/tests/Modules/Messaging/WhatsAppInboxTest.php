<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Http\Controllers\User\WhatsAppInboxController;
use Juwri\Modules\Messaging\Models\WhatsAppInbound;
use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WhatsAppInboxTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug('R').'-'.Str::random(5), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_index_groups_threads_with_unhandled_counts(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->storeFixture($owner);
        WhatsAppInbound::create(['store_id' => $store->id, 'from_phone' => '+1A', 'body' => 'hi', 'handled' => false, 'received_at' => now()]);
        WhatsAppInbound::create(['store_id' => $store->id, 'from_phone' => '+1A', 'body' => 'you there?', 'handled' => false, 'received_at' => now()]);
        WhatsAppInbound::create(['store_id' => $store->id, 'from_phone' => '+1B', 'contact_name' => 'Bob', 'body' => 'thanks', 'handled' => true, 'received_at' => now()]);

        $this->actingAs($owner);
        $threads = app(WhatsAppInboxController::class)->index($store)->getData()['threads'];

        $this->assertCount(2, $threads);
        $a = collect($threads)->firstWhere('phone', '+1A');
        $this->assertSame(2, $a['unhandled']);
    }

    public function test_reply_sends_and_marks_handled(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->storeFixture($owner);
        WhatsAppInbound::create(['store_id' => $store->id, 'from_phone' => '+15551111111', 'body' => 'hi', 'handled' => false, 'received_at' => now()]);
        $this->actingAs($owner);

        $req = Request::create('/x', 'POST', ['to' => '+15551111111', 'body' => 'On our way!']);
        app(WhatsAppInboxController::class)->reply($req, $store, app(WhatsAppService::class));

        $this->assertDatabaseHas('whatsapp_logs', ['to' => '+15551111111', 'feature' => 'general', 'direction' => 'out']);
        $this->assertDatabaseHas('whatsapp_inbound_messages', ['from_phone' => '+15551111111', 'handled' => true]);
    }

    public function test_non_owner_forbidden(): void
    {
        $store = $this->storeFixture(User::factory()->create(['status' => 1]));
        $this->actingAs(User::factory()->create(['status' => 1]));

        $this->expectException(HttpException::class);
        app(WhatsAppInboxController::class)->index($store);
    }
}
