<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Theme;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * P3-05: what a kit theme's Blade needs besides the view model — translations, plurals and the small output helpers
 * PORTING.md section 2 asks for, so the views stay free of `{!! !!}` and of any money or model access.
 *
 *   s()     a translated string (plain text; Blade escapes it)
 *   t()     a translated string as safe HTML: plain placeholders are escaped, an HtmlString placeholder
 *           (ltr(), money()) is dropped in as markup — "Order no. <bdi dir=ltr>123</bdi>"
 *   n()     a plural ("2 dishes", CLDR rules for the locale)
 *   money() the server's `Money.formatted`, untouched, in <bdi class="c-money"> (a discount's minus stays glued to
 *           its digits in RTL)
 *   ltr()   an order number / phone / code: one unbreakable left-to-right run
 *   flow()  free text from the view model (a status line, a step time, a choices label) with its time ranges, phone
 *           numbers, quantities and embedded amounts isolated and number+unit pairs kept together — the work the
 *           prototype's polish() post-pass did, done at the source as PORTING.md asks
 *   join()  spoken labels joined with the locale's list separator
 *
 * Every method returns text that is already escaped where it is markup, so a view prints it with `{{ }}`.
 */
final class KitView
{
    private const NBSP = "\u{00A0}";

    /** time range, international phone, tracking number, "2×", "+3", "−10%" */
    private const LTR_RUN = '/\d{1,2}:\d{2}(?:\s?[ap]m)?\s*[\x{2013}-]\s*\d{1,2}:\d{2}(?:\s?[ap]m)?'
        .'|\+\d{3} \d{2} \d{3} \d{4}'
        .'|(?<![\w.])\d{4} \d{4} \d{2}(?![\w])'
        .'|(?<![\w.])\d+\x{00D7}|(?<![\w])\+\d+(?![\w%])|(?<![\w])[\x{2212}-]\d+%/u';

    /** an amount that sits inside a label string: isolated symbol first or last, or a bare "SAR 68" / "68 ر.س" */
    private const MONEY_TEXT = '/\x{2068}[^\x{2069}]*\x{2069}\s?\d[\d.,]*|\d[\d.,]*\s?\x{2068}[^\x{2069}]*\x{2069}|SAR \d[\d.,]*|\d[\d.,]* \x{0631}\.\x{0633}/u';

    public function __construct(public readonly string $locale = 'en', public readonly string $dir = 'ltr') {}

    /** @param  array<string, mixed>  $vm */
    public static function forVm(array $vm): self
    {
        return new self((string) ($vm['store']['locale'] ?? app()->getLocale()), (string) ($vm['store']['dir'] ?? 'ltr'));
    }

    public function rtl(): bool
    {
        return $this->dir === 'rtl';
    }

    /** @param  array<string, mixed>  $replace */
    public function s(string $text, array $replace = []): string
    {
        return (string) ___($text, array_map(fn ($v) => $v instanceof HtmlString ? $v->toHtml() : $v, $replace));
    }

    /** @param  array<string, mixed>  $replace  HtmlString values are markup, the rest is escaped */
    public function t(string $text, array $replace = []): HtmlString
    {
        $marks = [];
        foreach ($replace as $key => $value) {
            $marks[$key] = "\u{E000}{$key}\u{E001}";
        }
        $out = e((string) ___($text, $marks));
        foreach ($replace as $key => $value) {
            $out = str_replace("\u{E000}{$key}\u{E001}", $value instanceof HtmlString ? $value->toHtml() : e((string) $value), $out);
        }

        return new HtmlString($out);
    }

    public function n(string $noun, int $count): string
    {
        return ___n($noun, $count);
    }

    /** @param  array{formatted: string}  $money */
    public function money(array $money): HtmlString
    {
        $text = e((string) $money['formatted']);
        $text = (string) preg_replace_callback('/[\x{2212}-]\d[\d.,]*/u', fn ($m) => '<bdi dir="ltr">'.$m[0].'</bdi>', $text);

        return new HtmlString('<bdi class="c-money">'.$text.'</bdi>');
    }

    public function ltr(string|int $value): HtmlString
    {
        return new HtmlString('<bdi dir="ltr" class="c-code">'.e((string) $value).'</bdi>');
    }

    public function bdi(string|int $value): HtmlString
    {
        return new HtmlString('<bdi>'.e((string) $value).'</bdi>');
    }

    public function flow(?string $text): HtmlString
    {
        $text = (string) $text;
        $rx = substr(self::MONEY_TEXT, 0, -2).'|'.substr(self::LTR_RUN, 1, -2).'/u';
        $pos = 0;
        $out = '';
        if (preg_match_all($rx, $text, $all, PREG_OFFSET_CAPTURE) !== false) {
            foreach ($all[0] as [$match, $at]) {
                $out .= e($this->units(substr($text, $pos, $at - $pos)));
                $nb = str_replace(' ', self::NBSP, $match);
                $out .= preg_match(self::MONEY_TEXT, $match) === 1
                    ? '<bdi class="c-money">'.e($nb).'</bdi>'
                    : '<bdi dir="ltr" class="c-code">'.e($nb).'</bdi>';
                $pos = $at + strlen($match);
            }
        }
        $out .= e($this->units(substr($text, $pos)));

        return new HtmlString($out);
    }

    /** @param  list<string>  $parts */
    public function join(array $parts): string
    {
        return implode($this->rtl() ? '، ' : ', ', array_filter($parts, fn ($p) => $p !== ''));
    }

    /** the click-to-chat link of a WhatsApp number (digits only), or '' when the number has none */
    public function whatsappUrl(?string $number): string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        return $digits === '' ? '' : 'https://wa.me/'.$digits;
    }

    /**
     * HTML the view model already carries sanitised by the core (an owner's custom block): printed as sent. The only place a kit
     * theme prints markup it did not build itself; the theme's views contain no raw echo.
     */
    public function sanitised(?string $html): HtmlString
    {
        return new HtmlString((string) $html);
    }

    /** the 2-letter monogram of a store name (the logo when no logo image is set) */
    public function monogram(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2))));
    }

    /** kit icon name -> inline SVG through <x-ui.icon> (unknown names print nothing) */
    public function icon(string $name, string $class = ''): HtmlString
    {
        $spec = KitIcons::resolve($name);
        if ($spec === null) {
            return new HtmlString('');
        }

        return new HtmlString(trim(view('components.kit.icon', ['name' => $name, 'class' => $class])->render()));
    }

    /** core-built `<p>text</p>` details as plain text for a fact chip (the markup is dropped, entities decoded) */
    public function plain(?string $html): string
    {
        return trim(html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function slug(string $text): string
    {
        return Str::slug($text, '_');
    }

    /** number + unit pairs keep together: "8 min", "250 g", "9:52 am" */
    private function units(string $text): string
    {
        $text = (string) preg_replace_callback('/(?<![\w.])(\d+(?:\.\d+)?) (kg|g|ml|L|min|h|am|pm)\b/u', fn ($m) => $m[1].self::NBSP.$m[2], $text);

        return preg_match('/\d'.self::NBSP.'h \d+'.self::NBSP.'min/u', $text) === 1 ? str_replace(self::NBSP.'h ', self::NBSP.'h'.self::NBSP, $text) : $text;
    }
}
