<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
namespace Tests\Modules\Agents;
use Juwri\Modules\Menu\Models\Menu; use App\Models\MenuCategory; use Juwri\Core\Models\Store; use Juwri\Core\Models\User;
use App\Services\Agents\AgentToolsService;
use Illuminate\Foundation\Testing\RefreshDatabase; use Illuminate\Support\Str; use Tests\TestCase;
class AgentUpsellTest extends TestCase {
    use RefreshDatabase;
    public function test_upsell_excludes_cart_and_is_scoped(): void {
        $o=User::factory()->create(['status'=>1,'email_verified_at'=>now()]);
        $t='R'.Str::random(5);
        $r=Store::create(['user_id'=>$o->id,'slug'=>Str::slug($t).'-'.Str::lower(Str::random(5)),'color'=>'#111','title'=>$t,'sub_title'=>'T','description'=>'T','main_image'=>'d.png','cover_image'=>'d.png','address'=>'1','phone'=>'1','created_at'=>now(),'updated_at'=>now()]);
        $c=MenuCategory::create(['store_id'=>$r->id,'name'=>'C']);
        $pizza=Menu::create(['store_id'=>$r->id,'category_id'=>$c->id,'name'=>'Pizza','price'=>30,'active'=>'1','is_sold_out'=>0]);
        $cola=Menu::create(['store_id'=>$r->id,'category_id'=>$c->id,'name'=>'Cola','price'=>5,'active'=>'1','is_sold_out'=>0]);
        $water=Menu::create(['store_id'=>$r->id,'category_id'=>$c->id,'name'=>'Water','price'=>2,'active'=>'1','is_sold_out'=>0]);
        // other restaurant item must not appear
        $r2=Store::create(['user_id'=>$o->id,'slug'=>Str::slug('x').'-'.Str::lower(Str::random(5)),'color'=>'#111','title'=>'X','sub_title'=>'T','description'=>'T','main_image'=>'d.png','cover_image'=>'d.png','address'=>'1','phone'=>'1','created_at'=>now(),'updated_at'=>now()]);
        $c2=MenuCategory::create(['store_id'=>$r2->id,'name'=>'C']);
        Menu::create(['store_id'=>$r2->id,'category_id'=>$c2->id,'name'=>'Foreign','price'=>1,'active'=>'1','is_sold_out'=>0]);
        $sug=AgentToolsService::suggestUpsell($r,[$pizza->id]); // pizza in cart
        $ids=array_column($sug,'item_id');
        $this->assertNotContains($pizza->id,$ids);      // excluded (in cart)
        $this->assertContains($water->id,$ids);          // cheapest first
        $this->assertContains($cola->id,$ids);
        foreach($sug as $s){ $this->assertNotSame('Foreign',$s['name']); } // scoped
    }
}
