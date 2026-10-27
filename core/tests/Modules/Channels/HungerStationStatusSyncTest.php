<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
namespace Tests\Modules\Channels;
use Juwri\Core\Models\Branch; use App\Models\Order; use Juwri\Core\Models\Store; use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationOrderService;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationStatusMapper as M;
use Illuminate\Foundation\Testing\RefreshDatabase; use Illuminate\Support\Str; use Tests\TestCase;
class HungerStationStatusSyncTest extends TestCase {
    use RefreshDatabase;
    public function test_status_mapper_per_mode(): void {
        $this->assertSame('READY_FOR_PICKUP', M::toHs('ready','partner'));
        $this->assertSame('DISPATCHED', M::toHs('completed','partner'));
        $this->assertSame('CANCELLED', M::toHs('cancelled','pelican'));
        $this->assertNull(M::toHs('completed','pelican')); // pelican doesn't emit dispatch
        $this->assertNull(M::toHs('preparing','partner'));
    }
    public function test_push_status_logs_out_event(): void {
        $o=User::factory()->create(['status'=>1,'email_verified_at'=>now()]);
        $t='R'.Str::random(5);
        $r=Store::create(['user_id'=>$o->id,'slug'=>Str::slug($t).'-'.Str::lower(Str::random(5)),'color'=>'#111','title'=>$t,'sub_title'=>'T','description'=>'T','main_image'=>'d.png','cover_image'=>'d.png','address'=>'1','phone'=>'1','created_at'=>now(),'updated_at'=>now()]);
        $b=Branch::where('store_id',$r->id)->first(); $b->update(['hs_chain_id'=>'C1']);
        $order=Order::create(['store_id'=>$r->id,'branch_id'=>$b->id,'type'=>'delivery','status'=>'ready','source'=>'hungerstation','hs_order_id'=>'HS1','hs_picking_mode'=>'partner']);
        $hs=app(HungerStationOrderService::class)->pushStatus($order);
        $this->assertSame('READY_FOR_PICKUP',$hs);
        $this->assertDatabaseHas('hungerstation_event_logs',['direction'=>'out','kind'=>'order.status','hs_order_id'=>'HS1']);
    }
    public function test_non_hs_order_is_ignored(): void {
        $order=new Order(['source'=>'qr','status'=>'ready']);
        $this->assertNull(app(HungerStationOrderService::class)->pushStatus($order));
    }
}
