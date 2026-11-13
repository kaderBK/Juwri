<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Contracts\Recommendations;
use Juwri\Modules\Menu\Data\Recommendation;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

class RecommendationEngineTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $o = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create(['user_id' => $o->id, 'slug' => 're-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'Re', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function item(Store $s, MenuCategory $c, string $name, float $price, array $extra = []): Menu
    {
        return Menu::create(array_merge(['store_id' => $s->id, 'category_id' => $c->id, 'name' => $name, 'price' => $price, 'active' => '1', 'is_sold_out' => 0], $extra));
    }

    private function engine(): Recommendations
    {
        return app(Recommendations::class);
    }

    private function ids(array $recs): array
    {
        return array_map(fn (Recommendation $r) => $r->itemId, $recs);
    }

    public function test_contract_is_bound_to_the_engine(): void
    {
        $this->assertInstanceOf(\Juwri\Modules\Menu\Services\Recommendations\RecommendationEngine::class, $this->engine());
    }

    public function test_agent_channel_cheapest_addons_with_reason_cap_and_cart_exclusion(): void
    {
        $s = $this->store();
        $c = MenuCategory::create(['store_id' => $s->id, 'name' => 'C']);
        $a = $this->item($s, $c, 'A', 1);
        $b = $this->item($s, $c, 'B', 2);
        $d = $this->item($s, $c, 'D', 3);
        $e = $this->item($s, $c, 'E', 4);
        $this->item($s, $c, 'Out', 0.5, ['is_sold_out' => 1]);
        $this->item($s, $c, 'Off', 0.5, ['active' => '0']);

        $recs = $this->engine()->recommend($s, Recommendations::CHANNEL_AGENT, [['item_id' => $a->id, 'qty' => 2]]);
        $this->assertSame([$b->id, $d->id, $e->id], $this->ids($recs));
        $this->assertSame(['cheapest_addon'], array_values(array_unique(array_map(fn ($r) => $r->reason, $recs))));
    }

    public function test_storefront_channel_same_category_as_last_line_cap_six(): void
    {
        $s = $this->store();
        $c1 = MenuCategory::create(['store_id' => $s->id, 'name' => 'Mains']);
        $c2 = MenuCategory::create(['store_id' => $s->id, 'name' => 'Drinks']);
        $m = [];
        for ($i = 1; $i <= 8; $i++) {
            $m[] = $this->item($s, $c1, "M$i", 10 + $i);
        }
        $drink = $this->item($s, $c2, 'Cola', 3);

        $recs = $this->engine()->recommend($s, Recommendations::CHANNEL_STOREFRONT, [$m[0]->id]);
        $this->assertSame([$m[1]->id, $m[2]->id, $m[3]->id, $m[4]->id, $m[5]->id, $m[6]->id], $this->ids($recs));
        $this->assertSame('same_category', $recs[0]->reason);
        $this->assertNotContains($drink->id, $this->ids($recs));
        $this->assertSame([], $this->engine()->recommend($s, Recommendations::CHANNEL_STOREFRONT, []));
    }

    public function test_kiosk_pos_and_unknown_channels_suggest_nothing_as_today(): void
    {
        $s = $this->store();
        $c = MenuCategory::create(['store_id' => $s->id, 'name' => 'C']);
        $a = $this->item($s, $c, 'A', 1);
        $this->item($s, $c, 'B', 2);
        foreach ([Recommendations::CHANNEL_KIOSK, Recommendations::CHANNEL_POS, 'nope'] as $ch) {
            $this->assertSame([], $this->engine()->recommend($s, $ch, [$a->id]));
        }
    }

    public function test_tenancy_two_stores_never_cross(): void
    {
        $s1 = $this->store();
        $s2 = $this->store();
        $c1 = MenuCategory::create(['store_id' => $s1->id, 'name' => 'C']);
        $c2 = MenuCategory::create(['store_id' => $s2->id, 'name' => 'C']);
        $own = $this->item($s1, $c1, 'Own', 5);
        $own2 = $this->item($s1, $c1, 'Own2', 6);
        $foreign = $this->item($s2, $c2, 'Foreign', 1);
        $foreign2 = $this->item($s2, $c2, 'Foreign2', 2);

        foreach ([Recommendations::CHANNEL_AGENT, Recommendations::CHANNEL_STOREFRONT] as $ch) {
            $ids = $this->ids($this->engine()->recommend($s1, $ch, [$own->id]));
            $this->assertSame([$own2->id], $ids, $ch);
            // a cart line from the other store is ignored and its category never leaks
            $ids = $this->ids($this->engine()->recommend($s1, $ch, [$foreign->id]));
            $this->assertNotContains($foreign->id, $ids);
            $this->assertNotContains($foreign2->id, $ids);
        }
        $this->assertSame([], $this->ids($this->engine()->recommend($s1, Recommendations::CHANNEL_STOREFRONT, [$foreign->id])));
    }
}
