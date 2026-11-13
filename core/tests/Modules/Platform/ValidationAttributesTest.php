<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * I5 §6 — the `attributes` map in ar/ur/hi/ru/zh validation.php now covers the
 * common checkout/auth/contact fields, so a validation error no longer splices
 * a raw English field name into an otherwise-translated sentence.
 */
class ValidationAttributesTest extends TestCase
{
    public static function locales(): array
    {
        return [['ar'], ['ur'], ['hi'], ['ru'], ['zh']];
    }

    #[DataProvider('locales')]
    public function test_common_fields_have_a_localized_attribute(string $locale): void
    {
        $map = include base_path("lang/$locale/validation.php");
        $attrs = $map['attributes'] ?? [];
        foreach (['name', 'email', 'phone', 'subject', 'message', 'password', 'address', 'code', 'quantity'] as $field) {
            $this->assertArrayHasKey($field, $attrs, "[$locale] missing attribute for '$field'");
            $this->assertNotSame('', trim((string) $attrs[$field]), "[$locale] empty attribute for '$field'");
            // The localized name must not be the raw English field key.
            $this->assertNotSame($field, $attrs[$field], "[$locale] '$field' left as English");
        }
    }

    public function test_required_error_uses_the_localized_field_name(): void
    {
        App::setLocale('ar');
        $v = Validator::make(['name' => ''], ['name' => 'required']);
        $message = $v->errors()->first('name');

        // The Arabic attribute (الاسم) must appear; the English word "name" must not.
        $this->assertStringContainsString('الاسم', $message);
        $this->assertStringNotContainsStringIgnoringCase('name', $message);
    }
}
