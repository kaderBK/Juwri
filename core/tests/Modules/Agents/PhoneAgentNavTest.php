<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Models\StoreOption;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-09: owner nav "Phone agent" + "Call log" entries follow the ai_phone_agent feature, EN + AR. */
class PhoneAgentNavTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        StoreOption::flushOptionMemo();
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return $owner;
    }

    /** @return list<string> */
    private function labels(User $owner, string $bust): array
    {
        $this->actingAs($owner, 'web');
        $route = new IlluminateRoute(['GET'], 'fake/'.$bust, [])->name('nav.probe.'.$bust);
        request()->setRouteResolver(fn () => $route);

        $labels = [];
        foreach (juwri_nav_model() as $tab) {
            foreach ($tab['groups'] as [$group, $items]) {
                foreach ($items as $i) {
                    $labels[] = (string) ($i['label'] ?? '');
                }
            }
        }

        return $labels;
    }

    private function setFeature(bool $on): void
    {
        config(['settings.admin_allow_ai_phone_agent' => $on ? 1 : 0]);
        FeatureRegistry::clearCache();
    }

    public function test_entries_visible_with_feature_in_both_locales(): void
    {
        $this->setFeature(true);
        $owner = $this->owner();

        app()->setLocale('en');
        $en = $this->labels($owner, 'en');
        $this->assertContains('Phone agent', $en);
        $this->assertContains('Call log', $en);

        app()->setLocale('ar');
        $ar = $this->labels($owner, 'ar');
        $this->assertContains('وكيل الهاتف', $ar);
        $this->assertContains('سجل المكالمات', $ar);
    }

    public function test_entries_hidden_without_feature(): void
    {
        $this->setFeature(false);
        $owner = $this->owner();

        foreach (['en', 'ar'] as $loc) {
            app()->setLocale($loc);
            $labels = $this->labels($owner, 'off'.$loc);
            $this->assertNotContains('Phone agent', $labels);
            $this->assertNotContains('وكيل الهاتف', $labels);
            $this->assertNotContains('Call log', $labels);
            $this->assertNotContains('سجل المكالمات', $labels);
        }
    }
}
