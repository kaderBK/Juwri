<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
namespace Tests\Modules\Analytics;
use Juwri\Modules\Menu\Models\Menu; use App\Models\MenuCategory; use App\Models\Order; use App\Models\OrderItem; use Juwri\Core\Models\Store; use Juwri\Core\Models\User;
use Juwri\Modules\Analytics\Services\RevenueDepthService;
use Illuminate\Foundation\Testing\RefreshDatabase; use Illuminate\Support\Str; use Tests\TestCase;
class RevenueDepthTest extends TestCase {
    use RefreshDatabase;
    public function test_aov_daypart_branch(): void {
        $o=User::factory()->create(['status'=>1,'email_verified_at'=>now()]);
        $t='R'.Str::random(5);
        $r=Store::create(['user_id'=>$o->id,'slug'=>Str::slug($t).'-'.Str::lower(Str::random(5)),'color'=>'#111','title'=>$t,'sub_title'=>'T','description'=>'T','main_image'=>'d.png','cover_image'=>'d.png','address'=>'1','phone'=>'1','created_at'=>now(),'updated_at'=>now()]);
        $c=MenuCategory::create(['store_id'=>$r->id,'name'=>'C']);
        $m=Menu::create(['store_id'=>$r->id,'category_id'=>$c->id,'name'=>'X','price'=>10,'active'=>'1']);
        // two morning orders (qty1 =10 each) branch null, one evening qty2=20
        $mk=function($qty,$hour)use($r,$m){ $ord=Order::create(['store_id'=>$r->id,'type'=>'takeaway','status'=>'completed','created_at'=>'2026-06-15 '.sprintf('%02d',$hour).':00:00']); OrderItem::create(['order_id'=>$ord->id,'item_id'=>$m->id,'quantity'=>$qty,'variation'=>0]); };
        $mk(1,9); $mk(1,10); $mk(2,20);
        $b=RevenueDepthService::breakdown($r->id,'2026-06-01','2026-06-30');
        $this->assertSame(3,$b['orders']);
        $this->assertSame(40.0,$b['revenue']); // 10+10+20
        $this->assertEqualsWithDelta(13.33,$b['aov'],0.01);
        $this->assertSame(20.0,$b['by_daypart']['morning']);
        $this->assertSame(20.0,$b['by_daypart']['evening']);
    }
}
