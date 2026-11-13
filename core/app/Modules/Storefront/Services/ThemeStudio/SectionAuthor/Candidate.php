<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor;

/**
 * Theme Studio phase E1: one model answer, normalised.
 *
 * The model returns {type, liquid, css, schema, locales:{en,ar}, fixture_notes}.
 * `liquid` is the section markup WITHOUT the schema; the schema is a JSON
 * object that source() appends as the file's single {% schema %} block — the
 * exact layout of every library section file.
 */
class Candidate
{
    public const TYPE_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    public const MAX_LIQUID_BYTES = 24000;

    /**
     * @param  array<string,mixed>  $schema
     * @param  array{en: array<string,string>, ar: array<string,string>}  $locales
     */
    public function __construct(
        public readonly string $type,
        public readonly string $liquid,
        public readonly string $css,
        public readonly array $schema,
        public readonly array $locales,
        public readonly string $fixtureNotes,
    ) {}

    /**
     * @param  array<mixed>  $answer
     * @return array{0: ?self, 1: list<string>} [candidate|null, shape problems]
     */
    public static function fromAnswer(array $answer): array
    {
        $problems = [];

        $type = is_string($answer['type'] ?? null) ? strtolower(trim($answer['type'])) : '';
        if ($type === '' || strlen($type) < 3 || strlen($type) > 40 || ! preg_match(self::TYPE_PATTERN, $type)) {
            $problems[] = 'type must be a lowercase slug of 3–40 characters (letters, digits, single dashes), e.g. "combo-builder".';
        }

        $liquid = is_string($answer['liquid'] ?? null) ? str_replace("\r\n", "\n", $answer['liquid']) : '';
        if (trim($liquid) === '') {
            $problems[] = 'liquid must be the section markup (a non-empty string).';
        } elseif (strlen($liquid) > self::MAX_LIQUID_BYTES) {
            $problems[] = 'liquid is '.strlen($liquid).' bytes (max '.self::MAX_LIQUID_BYTES.').';
        }
        if (preg_match('/{%-?\s*(end)?schema\b/i', $liquid)) {
            $problems[] = 'liquid must not contain {% schema %} — return the schema object in "schema".';
        }

        $css = is_string($answer['css'] ?? null) ? str_replace("\r\n", "\n", $answer['css']) : '';

        $schema = $answer['schema'] ?? null;
        if (is_string($schema)) {
            $decoded = json_decode($schema, true);
            $schema = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($schema) || array_is_list($schema) && $schema !== []) {
            $problems[] = 'schema must be a JSON object {name, group, verticals, settings, blocks?, presets}.';
            $schema = [];
        } else {
            if (! is_string($schema['name'] ?? null) || trim($schema['name']) === '') {
                $problems[] = 'schema.name is required.';
            }
            if (isset($schema['settings']) && ! is_array($schema['settings'])) {
                $problems[] = 'schema.settings must be a list.';
            }
            if (! is_array($schema['presets'] ?? null) || $schema['presets'] === []) {
                $problems[] = 'schema.presets must contain at least one preset ({"name": …}).';
            }
        }

        $locales = ['en' => [], 'ar' => []];
        foreach (['en', 'ar'] as $lang) {
            $map = $answer['locales'][$lang] ?? null;
            if (! is_array($map)) {
                $problems[] = "locales.{$lang} must be an object of string → string.";

                continue;
            }
            foreach ($map as $key => $value) {
                if (is_string($key) && is_string($value) && $key !== '' && strlen($key) <= 200 && strlen($value) <= 400) {
                    $locales[$lang][$key] = $value;
                } else {
                    $problems[] = "locales.{$lang} entries must be short string → string pairs.";
                    break;
                }
            }
        }

        $notes = is_string($answer['fixture_notes'] ?? null) ? mb_substr(trim($answer['fixture_notes']), 0, 2000) : '';

        if ($problems !== [] && ($type === '' || trim($liquid) === '')) {
            return [null, $problems];
        }

        return [new self($type, $liquid, $css, $schema, $locales, $notes), $problems];
    }

    /** The library file: markup + one {% schema %} block. */
    public function source(): string
    {
        $json = json_encode($this->schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return rtrim($this->liquid)."\n\n{% schema %}\n".$json."\n{% endschema %}\n";
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'liquid' => $this->liquid,
            'css' => $this->css,
            'schema' => $this->schema,
            'locales' => $this->locales,
            'fixture_notes' => $this->fixtureNotes,
        ];
    }

    /** @return list<string> the verticals the schema targets ('all' kept) */
    public function schemaVerticals(): array
    {
        return array_values(array_filter((array) ($this->schema['verticals'] ?? []), 'is_string'));
    }
}
