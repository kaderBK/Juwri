<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Http\Controllers\User\WhatsAppTemplateController;
use Juwri\Modules\Messaging\Models\WhatsAppTemplate;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WhatsAppTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug('R').'-'.Str::random(5), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_store_registers_template_and_upserts(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->storeFixture($owner);
        $this->actingAs($owner);

        $req = fn (array $o = []) => Request::create('/x', 'POST', array_merge([
            'name' => 'order_confirm', 'provider' => 'meta', 'language' => 'en',
            'category' => 'utility', 'status' => 'approved', 'body' => 'Hi',
        ], $o));

        app(WhatsAppTemplateController::class)->store($req(), $store);
        app(WhatsAppTemplateController::class)->store($req(['category' => 'marketing']), $store); // same key -> update

        $this->assertSame(1, WhatsAppTemplate::where('store_id', $store->id)->count());
        $this->assertSame('marketing', WhatsAppTemplate::first()->category);
    }

    public function test_store_rejects_bad_name(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->storeFixture($owner);
        $this->actingAs($owner);

        $this->expectException(ValidationException::class);
        app(WhatsAppTemplateController::class)->store(
            Request::create('/x', 'POST', ['name' => 'Bad Name!', 'provider' => 'meta', 'language' => 'en', 'category' => 'utility', 'status' => 'approved']),
            $store
        );
    }

    public function test_approved_names_helper_includes_platform_and_own(): void
    {
        $store = $this->storeFixture(User::factory()->create(['status' => 1]));
        WhatsAppTemplate::create(['store_id' => $store->id, 'provider' => 'meta', 'name' => 'own_t', 'status' => 'approved']);
        WhatsAppTemplate::create(['store_id' => null, 'provider' => 'meta', 'name' => 'platform_t', 'status' => 'approved']);
        WhatsAppTemplate::create(['store_id' => $store->id, 'provider' => 'meta', 'name' => 'draft_t', 'status' => 'draft']);

        $names = WhatsAppTemplate::approvedNames($store->id);
        $this->assertContains('own_t', $names);
        $this->assertContains('platform_t', $names);
        $this->assertNotContains('draft_t', $names);
    }

    public function test_destroy_only_own_template(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->storeFixture($owner);
        $other = $this->storeFixture(User::factory()->create(['status' => 1]));
        $foreign = WhatsAppTemplate::create(['store_id' => $other->id, 'provider' => 'meta', 'name' => 'x', 'status' => 'approved']);
        $this->actingAs($owner);

        $this->expectException(HttpException::class);
        app(WhatsAppTemplateController::class)->destroy($store, $foreign); // belongs to another restaurant -> 404
    }
}
