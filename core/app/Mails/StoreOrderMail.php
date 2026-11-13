<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Mails;

use Juwri\Core\Mail\SettingsTemplateMail;

/** New-order notice to the store owner (storefront checkout). */
class StoreOrderMail extends SettingsTemplateMail
{
    /** Fields typed by the (anonymous) customer or store; escaped in the body. */
    private const UNTRUSTED = ['RESTAURANT_NAME', 'CUSTOMER_NAME', 'TABLE_NUMBER', 'PHONE_NUMBER', 'ADDRESS', 'MESSAGE'];

    /** Order fields → tag names. */
    private const FIELDS = [
        'store_name' => 'RESTAURANT_NAME',
        'customer_name' => 'CUSTOMER_NAME',
        'table_number' => 'TABLE_NUMBER',
        'phone_number' => 'PHONE_NUMBER',
        'address' => 'ADDRESS',
        'order_type' => 'ORDER_TYPE',
        'order' => 'ORDER',
        'message' => 'MESSAGE',
    ];

    /** @param  array<string, mixed>  $order */
    public function __construct(private array $order) {}

    protected function template(): string
    {
        return 'new_order';
    }

    protected function mergeValues(): array
    {
        $values = $this->siteValues();
        foreach (self::FIELDS as $field => $tag) {
            $values[$tag] = $this->order[$field] ?? null;
        }

        return $values;
    }

    /**
     * `emails.default` outputs the codes unescaped, so a customer name like
     * `<a href=…>` would become a live link in the owner's inbox. Customer fields are HTML-escaped and `://` is broken with `&#58;`
     * (renders as ':') so no autolink forms. The subject stays raw (plain-text header).
     */
    protected function bodyValues(array $values): array
    {
        foreach (self::UNTRUSTED as $tag) {
            $values[$tag] = strtr(e($values[$tag]), ['://' => '&#58;//']);
        }

        return $values;
    }
}
