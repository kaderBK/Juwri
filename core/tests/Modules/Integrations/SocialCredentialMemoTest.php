<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Modules\Auth\Services\Social\SocialCredentialResolver;
use Tests\TestCase;

/** P4-16: a storefront page asks for the enabled social providers several times; the vault is read once per request. */
class SocialCredentialMemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeat_calls_in_one_request_read_the_vault_once(): void
    {
        $resolver = app(SocialCredentialResolver::class);
        $this->assertSame($resolver, app(SocialCredentialResolver::class), 'resolver is shared within a request');

        DB::enableQueryLog();
        $first = $resolver->enabledProviders(7);
        $n = count(DB::getQueryLog());
        $again = $resolver->enabledProviders(7);
        $this->assertSame($first, $again);
        $this->assertCount($n, DB::getQueryLog(), 'second call adds no query');
        $this->assertLessThanOrEqual(count(SocialCredentialResolver::PROVIDERS) * 2, $n);
    }
}
