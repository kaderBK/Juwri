<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Http\Controllers\Studio\StudioController;
use Juwri\Modules\Content\Models\StudioSession;
use Juwri\Modules\Content\Services\Studio\StudioCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SI7 (#60/#61): transparent pricing — balance, per-action cost, bundles.
 */
class StudioPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
        config(['studio.credit_cost.pdf_design' => 5]);
    }

    public function test_pricing_returns_balance_costs_and_bundles(): void
    {
        $s = StudioSession::create(['guest_token' => Str::random(48), 'expires_at' => now()->addHours(48)]);
        app(StudioCreditService::class)->credit($s, 12, 'topup');

        $req = Request::create('/studio/pricing', 'GET');
        $req->cookies->set('studio_guest', $s->guest_token);
        $data = new StudioController()->pricing($req)->getData(true);

        $this->assertSame(12, $data['balance']);
        $this->assertSame(5, $data['costs']['pdf_design']);
        $this->assertNotEmpty($data['bundles']);
        $this->assertSame(30, $data['bundles'][0]['credits']);   // Starter tier
        $this->assertSame('Starter', $data['bundles'][0]['name']);
    }
}
