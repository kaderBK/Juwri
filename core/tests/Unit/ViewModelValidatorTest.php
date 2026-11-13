<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit;

use Tests\Support\ViewModelValidator;
use Tests\TestCase;

/**
 * P3-02: the PHP port of the kit's validate_viewmodel.py. Parity: every kit fixture the Python tool accepts is
 * accepted here, and each kind of error the Python tool reports is reported here with the same wording.
 */
class ViewModelValidatorTest extends TestCase
{
    /** @return list<string> */
    private function fixtures(): array
    {
        $files = glob(dirname(base_path()).'/tools/themes/kit/viewmodel/fixtures/*.json') ?: [];
        sort($files);

        return $files;
    }

    private function smoke(): \stdClass
    {
        return json_decode((string) file_get_contents(dirname(base_path()).'/tools/themes/kit/viewmodel/fixtures/table.en.json'));
    }

    public function test_the_schema_is_the_kit_copy(): void
    {
        $this->assertFileExists(ViewModelValidator::schemaPath());
        $schema = ViewModelValidator::schema();
        $this->assertSame(['store', 'branch', 'context', 'theme', 'catalog', 'features', 'customer', 'endpoints', 'money', 'i18n'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function test_every_kit_fixture_validates_like_the_python_tool(): void
    {
        $files = $this->fixtures();
        $this->assertGreaterThanOrEqual(85, count($files), 'the kit ships 85 fixtures');
        foreach ($files as $file) {
            $this->assertSame([], ViewModelValidator::errorsForJson((string) file_get_contents($file)), basename($file));
        }
    }

    public function test_type_required_unexpected_and_enum_errors_use_the_python_wording(): void
    {
        $vm = $this->smoke();
        $vm->store->name = 12;
        unset($vm->store->slug);
        $vm->store->colour = 'red';
        $vm->store->dir = 'up';
        $vm->money->currency->exponent = 2.5;

        $errors = ViewModelValidator::errors($vm);

        $this->assertContains('$.store.name: expected string, got int', $errors);
        $this->assertContains("\$.store: missing 'slug'", $errors);
        $this->assertContains("\$.store: unexpected key 'colour'", $errors);
        $this->assertContains('$.store.dir: "up" not in ["ltr","rtl"]', $errors);
        $this->assertContains('$.money.currency.exponent: expected integer, got float', $errors);
    }

    public function test_cross_checks_the_schema_cannot_express(): void
    {
        $vm = $this->smoke();
        $first = array_key_first(get_object_vars($vm->catalog->items));
        $vm->catalog->categories[0]->item_ids[] = 'nope';
        $vm->catalog->items->{$first}->id = 'other';
        unset($vm->catalog->items->{$first}->price, $vm->catalog->items->{$first}->from_price);

        $errors = ViewModelValidator::errors($vm);

        $this->assertContains('category '.$vm->catalog->categories[0]->id.': unknown item "nope"', $errors);
        $this->assertContains("items.{$first}: id mismatch", $errors);
        $this->assertContains("items.{$first}: needs price or from_price", $errors);
    }

    public function test_an_empty_map_sent_as_a_json_list_is_an_error(): void
    {
        $vm = $this->smoke();
        $vm->i18n = [];
        $vm->theme->settings = [];

        $errors = ViewModelValidator::errors($vm);

        $this->assertContains('$.i18n: expected object, got list', $errors);
        $this->assertContains('$.theme.settings: expected object, got list', $errors);
    }

    public function test_nullable_types_and_refs_resolve(): void
    {
        $vm = $this->smoke();
        $vm->customer = null;
        $vm->context->table = null;
        $vm->context->min_order = (object) ['minor' => '5000', 'currency' => 'SAR', 'formatted' => 'SAR 50.00'];

        $this->assertSame(['$.context.min_order.minor: expected integer, got str'], ViewModelValidator::errors($vm));
    }
}
