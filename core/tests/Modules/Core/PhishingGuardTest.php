<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Rules\PhishingSlug;
use Tests\TestCase;

/**
 * Security A4 (upstream b80061178): store links on the
 * platform domain must not read like sign-in / account pages, and existing
 * stores are never renamed by the check.
 */
class PhishingGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_sign_in_and_account_words_are_detected(): void
    {
        foreach (['secure-login', 'account-verify', 'sign-in', 'juwri-support', 'reset_password', 'my-account', 'log-in-now', 'wallet-2fa'] as $slug) {
            $this->assertTrue(PhishingSlug::looksLikePhishing($slug), $slug);
        }
        foreach (['pizza-palace', 'badminton-cafe', 'blogger-bakery', 'neon-sign-bar', 'secured-burger', 'accountant-lunch'] as $slug) {
            $this->assertFalse(PhishingSlug::looksLikePhishing($slug), $slug);
        }
    }

    public function test_rule_refuses_new_slugs_but_keeps_an_unchanged_one(): void
    {
        $this->assertTrue(Validator::make(['slug' => 'secure-login'], ['slug' => [new PhishingSlug]])->fails());
        $this->assertFalse(Validator::make(['slug' => 'secure-login'], ['slug' => [new PhishingSlug('secure-login')]])->fails(), 'an existing store can save its current slug');
    }

    public function test_new_store_with_a_phishing_title_gets_a_neutral_slug_and_old_stores_are_untouched(): void
    {
        $owner = User::factory()->create();
        $base = ['user_id' => $owner->id, 'color' => '#111', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1'];

        $new = Store::create($base + ['title' => 'Secure Login', 'slug' => 'secure-login']);
        $this->assertStringStartsWith('store-', $new->slug);

        $new->forceFill(['slug' => 'account-verify'])->saveQuietly();
        $old = Store::find($new->id);
        $old->update(['title' => 'Renamed']);
        $this->assertSame('account-verify', $old->fresh()->slug, 'existing stores are never renamed');
    }

    /** A title with no letters or digits ("..") slugs to "" — every storefront
     * link and the owner's edit page then break. Such a store gets the same
     * neutral link as a phishing-looking title. */
    public function test_new_store_titled_with_no_letters_gets_a_usable_slug(): void
    {
        $owner = User::factory()->create();
        $base = ['user_id' => $owner->id, 'color' => '#111', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1'];

        $dots = Store::create($base + ['title' => '..']);

        $this->assertMatchesRegularExpression('/^store-[a-z0-9]{6}$/', $dots->slug, 'a title with no letters gets a usable link, not an empty one');
    }
}
