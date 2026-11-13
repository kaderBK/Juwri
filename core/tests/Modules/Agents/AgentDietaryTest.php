<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
namespace Tests\Modules\Agents;
use Juwri\Modules\Menu\Models\Menu; use App\Models\MenuCategory; use Juwri\Core\Models\Store; use Juwri\Core\Models\User;
use App\Services\Agents\AgentToolsService;
use Illuminate\Foundation\Testing\RefreshDatabase; use Illuminate\Support\Str; use Tests\TestCase;
class AgentDietaryTest extends TestCase {
    use RefreshDatabase;
    public function test_grounded_dietary_answer(): void {
        $o=User::factory()->create(['status'=>1,'email_verified_at'=>now()]);
        $t='R'.Str::random(5);
        $r=Store::create(['user_id'=>$o->id,'slug'=>Str::slug($t).'-'.Str::lower(Str::random(5)),'color'=>'#111','title'=>$t,'sub_title'=>'T','description'=>'T','main_image'=>'d.png','cover_image'=>'d.png','address'=>'1','phone'=>'1','created_at'=>now(),'updated_at'=>now()]);
        $c=MenuCategory::create(['store_id'=>$r->id,'name'=>'C']);
        $tagged=Menu::create(['store_id'=>$r->id,'category_id'=>$c->id,'name'=>'Salad','price'=>10,'active'=>'1','dietary_tags'=>['gluten_free'=>true,'vegan'=>false]]);
        $untagged=Menu::create(['store_id'=>$r->id,'category_id'=>$c->id,'name'=>'Mystery','price'=>10,'active'=>'1']);
        $this->assertSame('yes',AgentToolsService::dietaryAnswer($r,$tagged->id,'gluten_free'));
        $this->assertSame('no',AgentToolsService::dietaryAnswer($r,$tagged->id,'vegan'));
        $this->assertSame('unknown',AgentToolsService::dietaryAnswer($r,$tagged->id,'halal')); // tag absent -> no guess
        $this->assertSame('unknown',AgentToolsService::dietaryAnswer($r,$untagged->id,'gluten_free')); // no data
    }
}
