<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Juwri\Modules\Payments\Models\Transaction;
use Tests\TestCase;

/**
 * Found by the P1-12 Arabic journey: the superadmin dashboard and the owner's recent activity printed "App: BlastMail" and
 * "App purchase: blast-mail" in English on an Arabic screen. The stored text is untouched (receipts and accounting read it);
 * screens go through displayName() / displayDescription().
 */
class TransactionDisplayNameTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->setLocale('en');
        parent::tearDown();
    }

    public function test_system_written_names_are_shown_in_the_current_language(): void
    {
        $tx = new Transaction(['product_name' => 'App: BlastMail', 'transaction_description' => 'App purchase: blast-mail']);

        app()->setLocale('en');
        $this->assertSame('App: BlastMail', $tx->displayName());
        $this->assertSame('App purchase: blast-mail', $tx->displayDescription());

        app()->setLocale('ar');
        $this->assertSame('تطبيق: BlastMail', $tx->displayName());
        $this->assertSame('شراء تطبيق: blast-mail', $tx->displayDescription());

        $this->assertSame('App: BlastMail', $tx->product_name, 'the stored text is never rewritten');
    }

    public function test_every_shipped_locale_translates_both_labels(): void
    {
        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            app()->setLocale($locale);
            $tx = new Transaction(['product_name' => 'App: Zed', 'transaction_description' => 'App purchase: zed']);

            $this->assertStringContainsString('Zed', (string) $tx->displayName(), $locale);
            $this->assertStringContainsString('zed', (string) $tx->displayDescription(), $locale);
            $this->assertNotSame('App purchase: zed', $tx->displayDescription(), "[$locale] still English");
        }
    }

    public function test_a_wallet_top_up_written_in_english_shows_in_every_shipped_locale(): void
    {
        $tx = new Transaction(['product_name' => 'Wallet top-up', 'transaction_description' => 'Wallet top-up']);
        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotSame('Wallet top-up', $tx->displayName(), "[$locale] still English");
            $this->assertSame($tx->displayName(), $tx->displayDescription(), $locale);
        }
        app()->setLocale('ar');
        $this->assertSame('شحن المحفظة', $tx->displayName());
        $this->assertSame('Wallet top-up', $tx->product_name, 'the stored text is never rewritten');
    }

    public function test_other_names_and_nulls_pass_through(): void
    {
        app()->setLocale('ar');

        $this->assertSame('Pro Plan', (new Transaction(['product_name' => 'Pro Plan']))->displayName());
        $this->assertNull((new Transaction(['product_name' => null]))->displayName());
        $this->assertSame('', (new Transaction(['product_name' => '']))->displayName());
    }

    /** Found by the P3-15 sweep on the licensed Enterprise tree: a wallet top-up written in English showed as English on Arabic dashboards. */
    public function test_a_wallet_top_up_is_shown_in_every_shipped_locale(): void
    {
        $tx = new Transaction(['product_name' => 'Wallet top-up', 'transaction_description' => 'Wallet top-up']);
        app()->setLocale('en');
        $this->assertSame('Wallet top-up', $tx->displayName());

        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotSame('Wallet top-up', $tx->displayName(), "[$locale] still English");
            $this->assertNotSame('Wallet top-up', $tx->displayDescription(), "[$locale] still English");
        }
        $this->assertSame('Wallet top-up', $tx->product_name, 'the stored text is never rewritten');
    }
}
