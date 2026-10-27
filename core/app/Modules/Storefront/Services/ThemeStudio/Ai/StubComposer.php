<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\Ai;

use Juwri\Modules\Storefront\Services\ThemeStudio\Recipes;

/**
 * Theme Studio phase D: the deterministic `theme_generate` answers of
 * E2eStubAiService (tests, e2e, `studio:eval-ai` without --live). NEVER a
 * provider call.
 *
 *  - compose: reads the brief out of the data sentinel and answers three
 *    proposals built from the vertical's recipe skeletons (repaired with the
 *    same ProposalRules the builder uses), with distinct palettes/radius and
 *    short copy written in the brief's default language (English or Arabic;
 *    anything else gets the English copy);
 *  - translate (TranslatorService batches tagged `theme_generate`): each
 *    string marked with the target language — Arabic targets get Arabic
 *    wording, so language checks can tell the two apart.
 */
class StubComposer
{
    public const COPY = [
        'en' => [
            'hero' => 'Welcome to %s',
            'sub' => [
                'food' => 'Fresh favourites, ready when you are.',
                'goods' => 'Quality picks, delivered with care.',
                'services' => 'Book trusted service in a few taps.',
                'stays' => 'Stay in comfort, book in seconds.',
            ],
            'catalogue' => ['food' => 'Our menu', 'goods' => 'Shop the range', 'services' => 'Our services', 'stays' => 'Our rooms'],
            'about' => 'Our story',
            'names' => ['Signature', 'Bold', 'Calm'],
            'why' => ['Built around your best sellers.', 'Big type and strong colour that stand out.', 'Quiet, spacious and easy to browse.'],
        ],
        'ar' => [
            'hero' => 'أهلاً بكم في %s',
            'sub' => [
                'food' => 'أطباقكم المفضلة طازجة وجاهزة متى شئتم.',
                'goods' => 'منتجات مختارة بعناية تصلكم بسرعة.',
                'services' => 'احجزوا خدمة موثوقة ببضع نقرات.',
                'stays' => 'إقامة مريحة واحجزوا في ثوانٍ.',
            ],
            'catalogue' => ['food' => 'قائمتنا', 'goods' => 'تسوّقوا التشكيلة', 'services' => 'خدماتنا', 'stays' => 'غرفنا'],
            'about' => 'قصتنا',
            'names' => ['المميّز', 'الجريء', 'الهادئ'],
            'why' => ['مبني حول الأصناف الأكثر طلباً.', 'خطوط كبيرة وألوان قوية لافتة.', 'تصميم هادئ ومريح للتصفح.'],
        ],
    ];

    /**
     * @param  list<array<string,mixed>>  $messages
     * @return array<string,mixed>|null null = not a theme-builder call
     */
    public static function respond(array $messages): ?array
    {
        $system = '';
        $user = '';
        foreach ($messages as $m) {
            $content = is_string($m['content'] ?? null) ? $m['content'] : '';
            if (($m['role'] ?? '') === 'system') {
                $system = $content;
            } elseif (($m['role'] ?? '') === 'user' && $user === '') {
                $user = $content;
            }
        }

        if (preg_match('/<<<BRIEF\n(.*)\nBRIEF/s', $user, $m)) {
            $brief = json_decode($m[1], true);

            return is_array($brief) ? self::compose($brief) : null;
        }

        if (str_starts_with($user, 'Strings to translate') && preg_match('/\{.*\}/s', $user, $m)) {
            $strings = json_decode($m[0], true);
            $lang = preg_match('/into ([A-Za-z-]{2,10})\./', $system, $l) ? strtolower($l[1]) : 'xx';

            return is_array($strings) ? self::translate($strings, $lang) : null;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $brief
     * @return array{proposals:list<array<string,mixed>>}
     */
    public static function compose(array $brief): array
    {
        $storeType = (string) ($brief['store']['store_type'] ?? 'restaurant');
        $group = (string) ($brief['store']['vertical_group'] ?? 'food');
        $priority = $brief['owner']['priority'] ?? null;
        $lang = str_starts_with((string) ($brief['default_language'] ?? 'en'), 'ar') ? 'ar' : 'en';
        $copy = self::COPY[$lang];
        $name = (string) ($brief['store']['name'] ?? '');
        $tagline = trim((string) ($brief['store']['tagline'] ?? ''));

        $candidates = array_slice(Recipes::forVertical($storeType, is_string($priority) ? $priority : null), 0, 3);
        $themes = StudioThemes::all();
        $ownerColors = (array) ($brief['owner']['colors'] ?? []);

        $proposals = [];
        foreach ([0, 1, 2] as $i) {
            $recipe = $candidates[$i % max(1, count($candidates))] ?? null;
            if ($recipe === null) {
                break;
            }
            $theme = StudioThemes::forRecipe((string) $recipe['id'], $themes);
            $layout = Recipes::layout((string) $recipe['id']) ?? [];
            if ($theme !== null) {
                $layout = new ProposalRules($theme)->repair($layout, [
                    'group' => $group,
                    'store_type' => $storeType,
                    'ordering' => (bool) ($brief['ordering'] ?? false),
                    'priority' => $priority ?? $recipe['priority'],
                    'max_sections' => (int) config('theme_studio.ai.max_sections', 14),
                ]);
            }

            $sections = Catalogue::fromLayout($layout);
            foreach ($sections as $k => $section) {
                $settings = $section['settings'] ?? [];
                switch ($section['type']) {
                    case 'hero-cover':
                        $settings['heading'] = sprintf($copy['hero'], $name);
                        $settings['subheading'] = $lang === 'en' && $tagline !== '' ? $tagline : $copy['sub'][$group] ?? $copy['sub']['food'];
                        if ($i === 1) {
                            $settings['variant'] = 'split';
                        }
                        break;
                    case 'menu-grid':
                    case 'product-grid':
                    case 'service-list':
                    case 'room-list':
                        $settings['heading'] = $copy['catalogue'][$group] ?? $copy['catalogue']['food'];
                        break;
                    case 'about':
                        $settings['heading'] = $copy['about'];
                        break;
                }
                $sections[$k]['settings'] = $settings;
            }

            $tokens = $recipe['tokens'];
            if ($i === 0 && isset($ownerColors[0])) {
                $tokens['colors.primary'] = (string) $ownerColors[0];
            }
            if ($i === 1) {
                $tokens['layout.radius'] = 'pill';
                $tokens['layout.density'] = 'comfortable';
                $tokens['colors.accent'] = '#9b2c6f';
            }
            if ($i === 2) {
                $tokens['colors.background'] = '#111317';
                $tokens['colors.surface'] = '#1b1d22';
                $tokens['colors.text'] = '#f4f5f7';
                $tokens['layout.radius'] = 'soft';
            }

            $proposals[] = [
                'name' => $copy['names'][$i],
                'rationale' => $copy['why'][$i],
                'recipe_id' => $recipe['id'],
                'sections' => $sections,
                'tokens' => $tokens,
                'font_pair' => $recipe['font_pair'],
            ];
        }

        return ['proposals' => $proposals];
    }

    /**
     * @param  array<string,mixed>  $strings  s0 => text …
     * @return array<string,string>
     */
    public static function translate(array $strings, string $lang): array
    {
        $out = [];
        foreach ($strings as $key => $text) {
            $text = is_scalar($text) ? (string) $text : '';
            $out[(string) $key] = $lang === 'ar' ? 'بالعربية: '.$text : '['.$lang.'] '.$text;
        }

        return $out;
    }
}
