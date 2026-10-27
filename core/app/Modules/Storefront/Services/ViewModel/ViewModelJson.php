<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ViewModel;

/**
 * P3-02: the view model as JSON, shaped by the kit schema (viewmodel.schema.json next to this file — a byte copy of
 * tools/themes/kit/viewmodel/viewmodel.schema.json, test-guarded). The builder returns PHP arrays (what a Blade
 * theme reads); in PHP an empty map and an empty list are both `[]`, so a map the schema declares as an object
 * (theme.settings, i18n, catalog.items, a variant's options …) would reach the storefront JS as `[]`. This walks
 * the schema and turns every empty array at an object position into `{}`.
 */
final class ViewModelJson
{
    /** @var array<string, mixed>|null */
    private static ?array $schema = null;

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return self::$schema ??= json_decode((string) file_get_contents(__DIR__.'/viewmodel.schema.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $viewModel */
    public static function encode(array $viewModel, int $flags = 0): string
    {
        return json_encode(self::shape($viewModel, self::schema()), $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * One part of the view model as JSON, shaped by a `$defs` entry of the schema (e.g. `Quote` for the quote
     * endpoint).
     *
     * @param  array<string, mixed>  $value
     */
    public static function encodeDef(array $value, string $def, int $flags = 0): string
    {
        return json_encode(self::shape($value, ['$ref' => '#/$defs/'.$def]), $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private static function shape(mixed $value, array $schema): mixed
    {
        if (isset($schema['$ref'])) {
            $name = substr((string) strrchr((string) $schema['$ref'], '/'), 1);
            $schema = array_merge(self::schema()['$defs'][$name] ?? [], array_diff_key($schema, ['$ref' => true]));
        }
        if (! is_array($value)) {
            return $value;
        }

        $types = (array) ($schema['type'] ?? []);
        $isObject = in_array('object', $types, true) || isset($schema['properties']) || isset($schema['additionalProperties']);
        if ($isObject && ($value === [] || ! array_is_list($value))) {
            $properties = (array) ($schema['properties'] ?? []);
            $additional = $schema['additionalProperties'] ?? true;
            $out = new \stdClass;
            foreach ($value as $key => $child) {
                $childSchema = $properties[$key] ?? (is_array($additional) ? $additional : []);
                $out->{$key} = self::shape($child, $childSchema);
            }

            return $out;
        }

        $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];

        return array_map(fn ($child) => self::shape($child, $items), $value);
    }
}
