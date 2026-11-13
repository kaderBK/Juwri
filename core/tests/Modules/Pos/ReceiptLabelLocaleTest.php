<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * P4-13 regression: the raw order-type / payment-method keys ("takeaway", "on-table", "cash") were printed on
 * receipts and kitchen tickets as-is, so an Arabic receipt carried an English word. The labels now follow the
 * locale in every locale the product ships.
 */
class ReceiptLabelLocaleTest extends TestCase
{
    private const LOCALES = ['ar', 'de', 'en', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];

    protected function tearDown(): void
    {
        App::setLocale('en');
        parent::tearDown();
    }

    public function test_order_type_and_payment_labels_are_translated_in_arabic(): void
    {
        App::setLocale('ar');

        $this->assertSame('سفري', order_type_label('takeaway'));
        $this->assertSame('تناول بالمطعم', order_type_label('on-table'));
        $this->assertSame('خدمة الغرف', order_type_label('room_service'));
        $this->assertSame('نقداً', payment_method_label('cash'));
        $this->assertSame('البطاقة', payment_method_label('card'));
    }

    public function test_labels_exist_in_every_locale_and_never_fall_back_to_the_raw_key(): void
    {
        foreach (self::LOCALES as $locale) {
            App::setLocale($locale);
            foreach (['takeaway', 'on-table', 'delivery', 'room_service'] as $type) {
                $label = order_type_label($type);
                $this->assertNotSame('', $label, "{$locale}: {$type}");
                $this->assertNotSame($type, $label, "{$locale}: {$type} printed raw");
            }
            foreach (['cash', 'card'] as $method) {
                $this->assertNotSame($method, payment_method_label($method), "{$locale}: {$method} printed raw");
            }
        }

        App::setLocale('ar');
        foreach (['takeaway', 'on-table', 'delivery', 'room_service'] as $type) {
            $this->assertNotSame(order_type_label($type), (function () use ($type) {
                App::setLocale('en');
                $en = order_type_label($type);
                App::setLocale('ar');

                return $en;
            })(), "ar label for {$type} is identical to English");
        }
    }

    public function test_unknown_values_print_as_written_with_a_capital(): void
    {
        $this->assertSame('Pickup', order_type_label('pickup'));
        $this->assertSame('', order_type_label(null));
        $this->assertSame('Voucher', payment_method_label('voucher'));
    }
}
