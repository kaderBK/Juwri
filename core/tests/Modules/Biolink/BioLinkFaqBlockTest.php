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
 * bio-t15 Lane A / A9: the `faq` block (SPEC §2 A9) — a native
 * <details><summary> accordion (no JS, keyboard-accessible), config
 * {items:[{q,a}]} edited via the same "left | right" config_pairs textarea
 * idiom the controller already used for delivery/socials, remapped to q|a.
 */
class BioLinkFaqBlockTest extends TestCase
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

    private function render(Store $store): string
    {
        $row = $this->svc()->blocksFor($store->id)[0];

        return (string) view('bio._blocks.faq', ['block' => $row])->render();
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

    public function test_config_pairs_remap_to_q_and_a_through_the_real_controller(): void
    {
        [$owner, $store] = $this->store();
        config(['settings.admin_allow_biolink' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $resp = $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'faq',
            'config_pairs' => "Do you take reservations? | Yes, call us or use the reservation button above.\nIs parking available? | Free lot behind the building.",
        ]);

        $resp->assertOk();
        $saved = BioBlock::where('store_id', $store->id)->where('type', 'faq')->first();
        $this->assertNotNull($saved);
        $this->assertSame('Do you take reservations?', $saved->config['items'][0]['q']);
        $this->assertSame('Yes, call us or use the reservation button above.', $saved->config['items'][0]['a']);
    }

    public function test_renders_native_details_summary_and_escapes_the_answer(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'faq', 'label' => 'Questions',
            'config' => ['items' => [['q' => 'Open on Sundays?', 'a' => 'Yes <script>alert(1)</script>']]],
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString('<details class="bio-faq__item">', $html);
        $this->assertStringContainsString('<summary>Open on Sundays?</summary>', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_items_missing_q_or_a_are_dropped(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'faq',
            'config' => ['items' => [['q' => 'No answer'], ['a' => 'No question'], ['q' => 'Real Q', 'a' => 'Real A']]],
        ]);

        $html = $this->render($store);
        $this->assertSame(1, substr_count($html, '<details class="bio-faq__item">'));
        $this->assertStringContainsString('Real Q', $html);
    }

    public function test_no_items_degrades_to_nothing(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'faq', 'config' => []]);

        $this->assertSame('', trim($this->render($store)));
    }
}
