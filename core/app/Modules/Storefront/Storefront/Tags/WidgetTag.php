<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Tags;

use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Services\Rendering\ThemeOutputSanitizer;
use Keepsuit\Liquid\Exceptions\SyntaxException;
use Keepsuit\Liquid\Nodes\VariableLookup;
use Keepsuit\Liquid\Parse\TagParseContext;
use Keepsuit\Liquid\Parse\TokenType;
use Keepsuit\Liquid\Render\RenderContext;
use Keepsuit\Liquid\Tag;

/**
 * Theme Studio B0.7: `{% widget 'reservation' %}` — renders an ALLOW-LISTED,
 * platform-owned Blade partial (`store_templates/_partials/widgets/<name>`), so a
 * sandboxed Liquid theme can offer features that need server logic + JS
 * (booking, waitlist, loyalty, catering, store reviews, branch switcher) without
 * ever writing either.
 *
 *  - The name must be a string literal from {@see self::names()}. An unknown name
 *    is a parse error under the strict (upload / lint `liquid-syntax`)
 *    environment; the lenient live environment renders '' instead, so a diner
 *    never loses the page over it.
 *  - The partial's data comes ONLY from the server-set `widget_context` render
 *    register (ThemeContext::registers) — registers are not theme variables, so
 *    markup cannot forge the store, customer or feature switches.
 *  - A widget whose feature is off (StorefrontExtras features — the same gates
 *    the Blade themes use) renders ''.
 *  - Optional `label: <expr>` overrides the trigger text (escaped by Blade).
 *
 * Each widget's dialog + script is written once per page (tracked in the
 * context's `emitted` ArrayObject, shared by every section render), while its
 * trigger may appear any number of times.
 */
class WidgetTag extends Tag
{
    /**
     * name => the `features` switch that must be on (null = the partial self-gates).
     *
     * @var array<string,string|null>
     */
    protected const WIDGETS = [
        'reservation' => 'reservations',
        'waitlist' => 'waitlist',
        'catering' => 'catering',
        'loyalty' => 'loyalty',
        'store-reviews' => 'store_reviews',
        'branch-switcher' => null,
    ];

    /** Longest trigger label a theme may set. */
    protected const MAX_LABEL = 60;

    protected ?string $widget = null;

    protected mixed $labelExpression = null;

    public static function tagName(): string
    {
        return 'widget';
    }

    /**
     * The allow-list — the only names `{% widget %}` accepts.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::WIDGETS);
    }

    public function parse(TagParseContext $context): static
    {
        $strict = $context->getParseContext()->environment->defaultRenderContextOptions->rethrowErrors;

        $name = $context->params->expression();
        if (! is_string($name)) {
            if ($strict) {
                throw new SyntaxException('widget tag needs a quoted widget name, e.g. {% widget \'reservation\' %}');
            }
            $name = ''; // live: renders ''
        }

        while (! $context->params->isEnd()) {
            $context->params->consumeOrFalse(TokenType::Comma);
            $param = $context->params->expression();
            $key = is_string($param) || $param instanceof VariableLookup ? (string) $param : '';
            $context->params->consume(TokenType::Colon);
            $value = $context->params->expression();

            if ($key !== 'label') {
                if ($strict) {
                    throw new SyntaxException("widget tag: unknown parameter '{$key}' (allowed: label)");
                }

                continue;
            }
            $this->labelExpression = $value;
        }

        if (! array_key_exists($name, self::WIDGETS)) {
            if ($strict) {
                throw new SyntaxException("Unknown widget '{$name}'. Allowed: ".implode(', ', self::names()));
            }

            return $this; // live: renders ''
        }

        $this->widget = $name;

        return $this;
    }

    public function render(RenderContext $context): string
    {
        if ($this->widget === null) {
            return '';
        }

        $ctx = $context->getRegister('widget_context');
        if (! is_array($ctx) || ! ($ctx['store'] ?? null) instanceof Store || ! $ctx['store']->id) {
            return '';
        }

        $feature = self::WIDGETS[$this->widget];
        if ($feature !== null && empty($ctx['features'][$feature])) {
            return '';
        }

        $emitted = $ctx['emitted'] ?? null;
        $emitted = $emitted instanceof \ArrayObject ? $emitted : new \ArrayObject;

        try {
            $html = view('store_templates._partials.widgets.'.$this->widget, [
                'store' => $ctx['store'],
                'postOptions' => $ctx['postOptions'] ?? (object) [],
                'customer' => $ctx['customer'] ?? null,
                'table' => $ctx['table'] ?? null,
                'branches' => $ctx['branches'] ?? [],
                'activeBranch' => $ctx['activeBranch'] ?? null,
                'flags' => (array) ($ctx['flags'] ?? []),
                'features' => (array) ($ctx['features'] ?? []),
                'default_menu_language' => $ctx['default_menu_language'] ?? null,
                'widgetLabel' => $this->label($context),
                'widgetFirst' => ! isset($emitted[$this->widget]),
                'widgetBase' => ! isset($emitted['__base']),
            ])->render();
        } catch (\Throwable $e) {
            report($e); // a widget is an add-on — never take the storefront down

            return '';
        }

        if (trim($html) !== '') {
            $emitted[$this->widget] = true;
            if (str_contains($html, 'data-mbw-base')) {
                $emitted['__base'] = true;
            }
        }

        return ThemeOutputSanitizer::hold($html); // P6-263: trusted Blade partial
    }

    protected function label(RenderContext $context): ?string
    {
        if ($this->labelExpression === null) {
            return null;
        }
        $value = $context->evaluate($this->labelExpression);
        if (! is_scalar($value)) {
            return null;
        }
        $label = trim((string) $value);

        return $label === '' ? null : mb_substr($label, 0, self::MAX_LABEL);
    }
}
