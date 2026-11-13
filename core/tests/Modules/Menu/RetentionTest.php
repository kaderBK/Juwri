<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
namespace Tests\Modules\Menu;
use Juwri\Modules\Menu\Models\Menu; use App\Models\MenuCategory; use App\Models\Order; use App\Models\OrderItem; use Juwri\Core\Models\Store; use Juwri\Core\Models\User;
use Juwri\Modules\Marketing\Services\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase; use Illuminate\Support\Str; use Tests\TestCase;
class RetentionTest extends TestCase {
    use RefreshDatabase;
    public function test_returning_rate_and_rfm(): void {
        $o=User::factory()->create(['status'=>1,'email_verified_at'=>now()]);
        $t='R'.Str::random(5);
        $r=Store::create(['user_id'=>$o->id,'slug'=>Str::slug($t).'-'.Str::lower(Str::random(5)),'color'=>'#111','title'=>$t,'sub_title'=>'T','description'=>'T','main_image'=>'d.png','cover_image'=>'d.png','address'=>'1','phone'=>'1','created_at'=>now(),'updated_at'=>now()]);
        $c=MenuCategory::create(['store_id'=>$r->id,'name'=>'C']);
        $m=Menu::create(['store_id'=>$r->id,'category_id'=>$c->id,'name'=>'X','price'=>10,'active'=>'1']);
        $mk=function($cust,$qty)use($r,$m){ $ord=Order::create(['store_id'=>$r->id,'customer_id'=>$cust,'type'=>'takeaway','status'=>'completed','created_at'=>now()]); OrderItem::create(['order_id'=>$ord->id,'item_id'=>$m->id,'quantity'=>$qty,'variation'=>0]); };
        $mk(1,1); $mk(1,2); // customer 1: 2 orders (returning), monetary 30
        $mk(2,1);           // customer 2: 1 order
        $s=RetentionService::summary($r->id);
        $this->assertSame(2,$s['customers']);
        $this->assertSame(1,$s['returning_customers']);
        $this->assertSame(50.0,$s['returning_rate']);
        $this->assertSame(2,$s['rfm'][1]['frequency']);
        $this->assertSame(30.0,$s['rfm'][1]['monetary']);
    }
}
