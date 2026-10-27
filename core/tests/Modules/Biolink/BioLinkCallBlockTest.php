<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * bio-t15 Lane A / A2: the `call` block (SPEC §2 A2) — up to 3 buttons
 * (tel:/wa.me/sms:), each rendered only when its channel has a value AND its
 * `show` toggle is not explicitly off. WhatsApp numbers go through the
 * platform's existing digit-normalisation helpers (Arabic-Indic/Persian
 * numerals must survive, not vanish under a bare \D strip — WA-E3).
 */
class BioLinkCallBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    public function test_type_call_is_accepted_and_persisted_through_the_real_controller(): void
    {
        [$owner, $store] = $this->store();
        config(['settings.admin_allow_biolink' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $resp = $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'call',
            'label' => 'Reach us',
            'config' => ['phone' => '+1 234 567 8901', 'whatsapp' => '1234567890', 'show' => ['sms' => false]],
        ]);

        $resp->assertOk();
        $this->assertSame(1, BioBlock::where('store_id', $store->id)->where('type', 'call')->count());
        $saved = BioBlock::where('store_id', $store->id)->where('type', 'call')->first();
        $this->assertSame('+1 234 567 8901', $saved->config['phone']);
        $this->assertFalse($saved->config['show']['sms']);
    }

    public function test_renders_tel_and_whatsapp_but_not_missing_sms(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'call',
            'config' => ['phone' => '+1 234 567 8901', 'whatsapp' => '1234567890'],
        ]);

        $row = $this->svc()->blocksFor($store->id)[0];
        $html = (string) view('bio._blocks.call', ['block' => $row])->render();

        $this->assertStringContainsString('href="tel:+12345678901"', $html);
        $this->assertStringContainsString('href="https://api.whatsapp.com/send?phone=1234567890"', $html);
        $this->assertStringNotContainsString('sms:', $html);
    }

    public function test_arabic_indic_digits_survive_normalisation_for_whatsapp_and_call(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'call',
            // "١٢٣٤٥٦٧٨٩٠" == "1234567890" in Arabic-Indic numerals.
            'config' => ['phone' => '١٢٣٤٥٦٧٨٩٠', 'whatsapp' => '١٢٣٤٥٦٧٨٩٠'],
        ]);

        $row = $this->svc()->blocksFor($store->id)[0];
        $html = (string) view('bio._blocks.call', ['block' => $row])->render();

        $this->assertStringContainsString('tel:1234567890', $html);
        $this->assertStringContainsString('phone=1234567890', $html);
    }

    public function test_show_toggle_hides_a_configured_channel(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'call',
            'config' => ['phone' => '15551234567', 'sms' => '15551234567', 'show' => ['sms' => false]],
        ]);

        $row = $this->svc()->blocksFor($store->id)[0];
        $html = (string) view('bio._blocks.call', ['block' => $row])->render();

        $this->assertStringContainsString('tel:15551234567', $html);
        $this->assertStringNotContainsString('sms:15551234567', $html);
    }

    public function test_all_channels_empty_degrades_to_nothing(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'call', 'config' => []]);

        $row = $this->svc()->blocksFor($store->id)[0];
        $html = trim((string) view('bio._blocks.call', ['block' => $row])->render());

        $this->assertSame('', $html);
    }
}
