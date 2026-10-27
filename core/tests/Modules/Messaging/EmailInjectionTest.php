<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Mails\StoreOrderMail;
use Tests\TestCase;

/**
 * The order-notification email (emails/default.blade.php) emits short-code values
 * through {!! !!} + Markdown. Fields from the anonymous storefront checkout must be
 * neutralized for BOTH raw HTML and Markdown link syntax, or a customer name/note
 * injects a phishing link into the store owner's email.
 */
class EmailInjectionTest extends TestCase
{
    public function test_order_email_neutralizes_a_phishing_link_in_customer_fields(): void
    {
        config([
            'settings.site_title' => 'T',
            'settings.email_sub_new_order' => 'New order from {CUSTOMER_NAME}',
            'settings.email_message_new_order' => "Order from {CUSTOMER_NAME}\n\nNote: {MESSAGE}\n\nAddr: {ADDRESS}",
        ]);

        $html = new StoreOrderMail([
            'store_name' => 'R',
            'customer_name' => '[Verify your payout account](https://evil.example)', // Markdown link
            'table_number' => '1',
            'phone_number' => '555',
            'address' => 'https://evil3.example',                                    // bare URL (autolink)
            'order_type' => 'delivery',
            'order' => 'Item x1',
            'message' => '<a href="https://evil2.example">click me</a>',              // raw HTML anchor
        ])->render();

        // NONE of the three vectors may become a LIVE anchor in the owner's email.
        // A live tag is `<a href=...`; a neutralized one renders as text (`&lt;a href=...`).
        $this->assertStringNotContainsString('<a href="https://evil', $html, 'no live anchor to any injected URL');
        // Proof it was neutralized (rendered as visible text), not silently stripped.
        $this->assertStringContainsString('&lt;a href=', $html, 'raw HTML anchor shown as text');
    }
}
