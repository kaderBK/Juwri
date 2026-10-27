<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
namespace Tests\Modules\Channels;
use Juwri\Core\Models\Branch; use Juwri\Modules\Menu\Models\Menu; use App\Models\MenuCategory; use Juwri\Core\Models\Store; use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase; use Illuminate\Support\Str; use Tests\TestCase;
class HungerStationCatalogTest extends TestCase {
    use RefreshDatabase;
    public function test_translate_and_push_records_job(): void {
        $o=User::factory()->create(['status'=>1,'email_verified_at'=>now()]);
        $t='R'.Str::random(5);
        $r=Store::create(['user_id'=>$o->id,'slug'=>Str::slug($t).'-'.Str::lower(Str::random(5)),'color'=>'#111','title'=>$t,'sub_title'=>'T','description'=>'T','main_image'=>'d.png','cover_image'=>'d.png','address'=>'1','phone'=>'1','created_at'=>now(),'updated_at'=>now()]);
        $b=Branch::where('store_id',$r->id)->first(); $b->update(['hs_vendor_id'=>'V1','hs_chain_id'=>'C1']);
        $c=MenuCategory::create(['store_id'=>$r->id,'name'=>'C']);
        $m=Menu::create(['store_id'=>$r->id,'category_id'=>$c->id,'name'=>'Pizza','price'=>30,'active'=>'1','hs_sku'=>'SKU-1']);
        Menu::create(['store_id'=>$r->id,'category_id'=>$c->id,'name'=>'NoSku','price'=>5,'active'=>'1']); // no sku -> excluded
        $payload=HungerStationCatalogService::translate($m);
        $this->assertSame('SKU-1',$payload['sku']); $this->assertSame(30.0,$payload['price']); $this->assertTrue($payload['active']);
        $job=new HungerStationCatalogService()->pushBranch($r->id,$b->id);
        $this->assertSame('update',$job->kind);
        $this->assertSame(1,(int)$job->payload['items']); // only the sku-mapped item
    }
}
