<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

/**
 * P3-02: PHP port of the kit's tools/validate_viewmodel.py (tools/themes/tools/), as a test helper.
 *
 * Supports exactly the JSON Schema subset the kit schema uses — `type` (incl. lists), `enum`, `required`,
 * `properties`, `additionalProperties` (bool or schema), `items`, `$ref` to `#/$defs` — and ignores every other
 * keyword (description, $id, title), like the Python original. Plus the original's cross-checks the schema cannot
 * express: every category item id exists in `catalog.items`, an item's `id` equals its key, every item has `price`
 * or `from_price`.
 *
 * It validates JSON, not PHP arrays: give it the decoded document (objects as \stdClass, lists as arrays), so an
 * empty map that would be sent as `[]` is caught. Error messages match the Python wording.
 */
final class ViewModelValidator
{
    /** @var array<string, mixed>|null */
    private static ?array $schema = null;

    public static function schemaPath(): string
    {
        return dirname(base_path()).'/tools/themes/kit/viewmodel/viewmodel.schema.json';
    }

    /** @return array<string, mixed> the schema as an associative array */
    public static function schema(): array
    {
        return self::$schema ??= json_decode((string) file_get_contents(self::schemaPath()), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Errors for one decoded view model (empty list = valid).
     *
     * @return list<string>
     */
    public static function errors(mixed $document): array
    {
        $errors = [];
        self::check($document, self::schema(), '$', $errors);

        $catalog = $document instanceof \stdClass && isset($document->catalog) && $document->catalog instanceof \stdClass
            ? $document->catalog : null;
        $items = $catalog !== null && isset($catalog->items) && $catalog->items instanceof \stdClass ? $catalog->items : new \stdClass;
        foreach (($catalog->categories ?? []) as $category) {
            foreach ((array) ($category->item_ids ?? []) as $id) {
                if (! is_string($id) || ! property_exists($items, $id)) {
                    $errors[] = 'category '.(string) ($category->id ?? '?').': unknown item '.json_encode($id);
                }
            }
        }
        foreach (get_object_vars($items) as $key => $item) {
            if (! $item instanceof \stdClass) {
                continue;
            }
            if (($item->id ?? null) !== (string) $key) {
                $errors[] = "items.{$key}: id mismatch";
            }
            if (! property_exists($item, 'price') && ! property_exists($item, 'from_price')) {
                $errors[] = "items.{$key}: needs price or from_price";
            }
        }

        return $errors;
    }

    /**
     * Errors of one decoded part of a view model against a `$defs` entry (e.g. `Quote`).
     *
     * @return list<string>
     */
    public static function errorsForDef(mixed $value, string $def): array
    {
        $errors = [];
        self::check($value, ['$ref' => '#/$defs/'.$def], '$', $errors);

        return $errors;
    }

    /** @return list<string> errors of a JSON string */
    public static function errorsForJson(string $json): array
    {
        return self::errors(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $errors
     */
    private static function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        if (isset($schema['$ref'])) {
            $name = substr((string) strrchr((string) $schema['$ref'], '/'), 1);
            $resolved = self::schema()['$defs'][$name];
            unset($schema['$ref']);
            $schema = array_merge($resolved, $schema);
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            $ok = false;
            foreach ($types as $type) {
                if (self::typeOk($value, (string) $type)) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                $errors[] = "{$path}: expected ".(is_array($schema['type']) ? json_encode($schema['type']) : $schema['type']).', got '.self::typeName($value);

                return;
            }
        }

        if (array_key_exists('enum', $schema) && ! self::inEnum($value, (array) $schema['enum'])) {
            $errors[] = "{$path}: ".json_encode($value, JSON_UNESCAPED_UNICODE).' not in '.json_encode($schema['enum'], JSON_UNESCAPED_UNICODE);
        }

        if ($value instanceof \stdClass) {
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! property_exists($value, (string) $required)) {
                    $errors[] = "{$path}: missing '{$required}'";
                }
            }
            $properties = (array) ($schema['properties'] ?? []);
            $additional = $schema['additionalProperties'] ?? true;
            foreach (get_object_vars($value) as $key => $child) {
                if (array_key_exists($key, $properties)) {
                    self::check($child, $properties[$key], "{$path}.{$key}", $errors);
                } elseif ($additional === false) {
                    $errors[] = "{$path}: unexpected key '{$key}'";
                } elseif (is_array($additional)) {
                    self::check($child, $additional, "{$path}.{$key}", $errors);
                }
            }
        }

        if (is_array($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $i => $child) {
                self::check($child, $schema['items'], "{$path}[{$i}]", $errors);
            }
        }
    }

    private static function typeOk(mixed $value, string $type): bool
    {
        return match ($type) {
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            'object' => $value instanceof \stdClass,
            'array' => is_array($value) && array_is_list($value),
            default => false,
        };
    }

    /** @param  list<mixed>  $enum */
    private static function inEnum(mixed $value, array $enum): bool
    {
        foreach ($enum as $allowed) {
            if ($allowed === $value || (is_int($allowed) && is_float($value) && (float) $allowed === $value)) {
                return true;
            }
        }

        return false;
    }

    private static function typeName(mixed $value): string
    {
        return match (true) {
            $value instanceof \stdClass => 'dict',
            is_array($value) => 'list',
            is_string($value) => 'str',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            $value === null => 'NoneType',
            default => get_debug_type($value),
        };
    }
}
