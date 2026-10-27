<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\MenuReview;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Media\Models\MediaAsset;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * F6 · Rich reviews backend coverage:
 *  - distribution aggregate correctness
 *  - is_verified true only from a delivered order containing the item
 *  - photo persistence
 *  - each filter query returns the right subset
 */
class MenuReviewRichTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Menu $menu;

    private Menu $otherItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(999999); // P1-04 5b: fixture store ids must be real stores

        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->store = Store::create([
            'user_id' => $owner->id,
            'slug' => 'rev-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Rev', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $this->store->id, 'name' => 'C']);
        $this->menu = Menu::create(['store_id' => $this->store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => '1']);
        $this->otherItem = Menu::create(['store_id' => $this->store->id, 'category_id' => $cat->id, 'name' => 'Fries', 'price' => 5, 'active' => '1']);
    }

    private function review(array $attrs = []): MenuReview
    {
        return MenuReview::create(array_merge([
            'menu_id' => $this->menu->id,
            'store_id' => $this->store->id,
            'reviewer_name' => 'A',
            'stars' => 5,
            'body' => 'good',
            'status' => 'approved',
            'is_verified' => false,
        ], $attrs));
    }

    public function test_distribution_and_average(): void
    {
        $this->review(['stars' => 5]);
        $this->review(['stars' => 5]);
        $this->review(['stars' => 3]);
        $this->review(['stars' => 1]);
        // pending + other-item reviews must NOT count
        $this->review(['stars' => 5, 'status' => 'pending']);
        $this->review(['stars' => 2, 'menu_id' => $this->otherItem->id]);

        $res = $this->getJson("/r/{$this->store->id}/item/{$this->menu->id}/reviews");
        $res->assertOk();

        $this->assertSame(['5' => 2, '4' => 0, '3' => 1, '2' => 0, '1' => 1], $res->json('distribution'));
        $this->assertSame(4, $res->json('count'));
        $this->assertEquals(3.5, $res->json('average')); // (5+5+3+1)/4
    }

    public function test_is_verified_only_from_delivered_order_with_item(): void
    {
        $buyer = Customer::create(['store_id' => $this->store->id, 'name' => 'Buyer', 'phone' => UniquePhone::next('5')]);
        $ord = Order::create(['store_id' => $this->store->id, 'customer_id' => $buyer->id, 'type' => 'delivery', 'status' => 'delivered', 'created_at' => now()]);
        OrderItem::create(['order_id' => $ord->id, 'item_id' => $this->menu->id, 'quantity' => 1, 'variation' => 0]);

        $raw = Str::random(40);
        CustomerToken::create(['customer_id' => $buyer->id, 'token' => hash('sha256', $raw), 'name' => 't', 'expires_at' => null]);

        $res = $this->postJson("/r/{$this->store->id}/item/{$this->menu->id}/reviews", [
            'rating' => 5, 'text' => 'verified!',
        ], ['Authorization' => 'Bearer '.$raw]);
        $res->assertCreated();
        $this->assertTrue($res->json('review.is_verified'));

        // Same buyer reviewing a DIFFERENT item they never ordered → not verified.
        $res2 = $this->postJson("/r/{$this->store->id}/item/{$this->otherItem->id}/reviews", [
            'rating' => 4,
        ], ['Authorization' => 'Bearer '.$raw]);
        $res2->assertCreated();
        $this->assertFalse($res2->json('review.is_verified'));

        // Anonymous review → not verified.
        $res3 = $this->postJson("/r/{$this->store->id}/item/{$this->menu->id}/reviews", ['rating' => 5, 'name' => 'Anon']);
        $res3->assertCreated();
        $this->assertFalse($res3->json('review.is_verified'));
        $this->assertDatabaseHas('menu_reviews', ['reviewer_name' => 'Anon', 'customer_id' => null, 'is_verified' => false]);
    }

    public function test_non_delivered_order_does_not_verify(): void
    {
        $buyer = Customer::create(['store_id' => $this->store->id, 'name' => 'B2', 'phone' => UniquePhone::next('5')]);
        // completed (pickup) order — NOT delivered
        $ord = Order::create(['store_id' => $this->store->id, 'customer_id' => $buyer->id, 'type' => 'takeaway', 'status' => 'completed', 'created_at' => now()]);
        OrderItem::create(['order_id' => $ord->id, 'item_id' => $this->menu->id, 'quantity' => 1, 'variation' => 0]);

        $raw = Str::random(40);
        CustomerToken::create(['customer_id' => $buyer->id, 'token' => hash('sha256', $raw), 'name' => 't', 'expires_at' => null]);

        $res = $this->postJson("/r/{$this->store->id}/item/{$this->menu->id}/reviews", ['rating' => 5], ['Authorization' => 'Bearer '.$raw]);
        $res->assertCreated();
        $this->assertFalse($res->json('review.is_verified'));
    }

    public function test_photo_persistence(): void
    {
        $a1 = MediaAsset::create(['store_id' => $this->store->id, 'filename' => 'a.png', 'folder' => 'other']);
        $a2 = MediaAsset::create(['store_id' => $this->store->id, 'filename' => 'b.png', 'folder' => 'other']);
        // asset from another restaurant must be ignored
        $foreign = MediaAsset::create(['store_id' => 999999, 'filename' => 'x.png', 'folder' => 'other']);

        $res = $this->postJson("/r/{$this->store->id}/item/{$this->menu->id}/reviews", [
            'rating' => 4, 'text' => 'nice', 'name' => 'P', 'photos' => [$a1->id, $a2->id, $foreign->id],
        ]);
        $res->assertCreated();

        $reviewId = $res->json('review.id');
        $this->assertDatabaseHas('menu_review_media', ['review_id' => $reviewId, 'media_asset_id' => $a1->id]);
        $this->assertDatabaseHas('menu_review_media', ['review_id' => $reviewId, 'media_asset_id' => $a2->id]);
        $this->assertDatabaseMissing('menu_review_media', ['review_id' => $reviewId, 'media_asset_id' => $foreign->id]);
        $this->assertCount(2, $res->json('review.photos'));
    }

    public function test_filters_return_right_subset(): void
    {
        $a1 = MediaAsset::create(['store_id' => $this->store->id, 'filename' => 'a.png', 'folder' => 'other']);

        // verified + detailed (has body + photo)
        $verifiedDetailed = $this->review(['reviewer_name' => 'VD', 'is_verified' => true, 'body' => 'full']);
        $verifiedDetailed->media()->create(['media_asset_id' => $a1->id, 'position' => 0]);
        // verified, no photo (not detailed)
        $this->review(['reviewer_name' => 'V', 'is_verified' => true, 'body' => 'x']);
        // plain, no body no photo
        $this->review(['reviewer_name' => 'Plain', 'is_verified' => false, 'body' => null]);

        $base = "/r/{$this->store->id}/item/{$this->menu->id}/reviews";

        $verified = $this->getJson("$base?filter=verified");
        $this->assertSame(2, $verified->json('meta.total'));
        foreach ($verified->json('data') as $row) {
            $this->assertTrue($row['is_verified']);
        }

        $detailed = $this->getJson("$base?filter=detailed");
        $this->assertSame(1, $detailed->json('meta.total'));
        $this->assertSame('VD', $detailed->json('data.0.name'));

        $latest = $this->getJson("$base?filter=latest");
        $this->assertSame(3, $latest->json('meta.total'));
    }
}
