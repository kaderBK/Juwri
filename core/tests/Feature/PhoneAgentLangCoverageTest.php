<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * P6-09 V-19 phone-agent i18n coverage. The voice surface mixes TWO translation
 * systems: __() reads lang/ar.json keyed by the literal English sentence, and ___()
 * reads lang/<locale>/lang.php keyed by Str::slug(text, '_'). Every literal first
 * argument found in the phone-agent sources must resolve in Arabic — an English
 * fallback is what an Arabic caller HEARS (TTS) or an owner SEES in the dashboard.
 */
class PhoneAgentLangCoverageTest extends TestCase
{
    /**
     * The phone-agent strings the brief requires in BOTH lang files, even when a
     * source reference is not live yet (e.g. the call-language dropdown).
     */
    private const REQUIRED = [
        'i_cant_take_card_details_by_phone_ill_text_you_a_secure_payment_link_instead',
        'recording_region_is_required_when_recording_is_on',
        'staff_handoff_number',
        'active_hours',
        'eg_0900_2300',
        'monthly_minute_cap',
        'leave_empty_for_no_cap',
        'recording_region',
        'european_union',
        'record_calls',
        'payment_by_link_only',
        'view_all_calls',
        'call_details',
        'call_log',
        'back_to_calls',
        'caller',
        'recorded',
        'not_recorded',
        'transcript',
        'no_transcript_recorded',
        'agent',
        'every_call_your_ai_phone_agent_handled',
        'call_language',
        'automatic_store_language',
        'the_language_the_phone_agent_speaks_on_this_line',
    ];

    /** The phone-agent sources the coverage scan runs over. */
    private function sources(): array
    {
        $files = [
            app_path('Modules/Agents/Http/Controllers/User/PhoneAgentController.php'),
            app_path('Modules/Agents/Http/Controllers/VoiceWebhookController.php'),
            app_path('Modules/Agents/Http/Controllers/VoiceBridgeController.php'),
            resource_path('views/owner/kitchen/kds.blade.php'),
        ];

        foreach ([
            app_path('Modules/Agents/Services/Telephony'),
            resource_path('views/owner/phone-agent'),
        ] as $dir) {
            foreach ((new Finder)->files()->in($dir) as $file) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every literal first argument of __() and ___() in the scanned sources,
     * keyed by helper, literal text => relative file path.
     *
     * @return array{__: array<string, string>, ___: array<string, string>}
     */
    private function literals(): array
    {
        $pattern = '/(_{2,3})\(\s*([\'"])(.*?)(?<!\\\\)\2/s';
        $found = ['__' => [], '___' => []];

        foreach ($this->sources() as $file) {
            $content = (string) file_get_contents($file);
            if (! preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $m) {
                $helper = strlen($m[1]) === 3 ? '___' : '__';
                $literal = str_replace(['\\'.$m[2], '\\\\'], [$m[2], '\\'], $m[3]);
                $found[$helper][$literal] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);
            }
        }

        return $found;
    }

    /** lang/<locale>/lang.php as an array (the file returns the table). */
    private function langTable(string $locale): array
    {
        $path = base_path("lang/$locale/lang.php");
        $this->assertFileExists($path);

        return include $path;
    }

    /** lang/ar.json as an array. */
    private function arJson(): array
    {
        $path = base_path('lang/ar.json');
        $this->assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'lang/ar.json must be valid JSON');

        return $decoded;
    }

    /** The `:word` placeholder tokens of a source string. */
    private function placeholders(string $text): array
    {
        preg_match_all('/:([\p{L}\p{N}]+)/u', $text, $m);

        return $m[0];
    }

    public function test_every_phone_agent_literal_resolves_in_arabic(): void
    {
        $en = $this->langTable('en');
        $ar = $this->langTable('ar');
        $arJson = $this->arJson();
        $problems = [];

        foreach ($this->literals()['__'] as $text => $file) {
            if (! array_key_exists($text, $arJson)) {
                $problems[] = "[$file] __() key not in lang/ar.json: $text";
                continue;
            }
            if ($arJson[$text] === $text) {
                $problems[] = "[$file] __() Arabic is still English in lang/ar.json: $text";
            }
            foreach ($this->placeholders($text) as $ph) {
                if (! str_contains($arJson[$text], $ph)) {
                    $problems[] = "[$file] __() dropped $ph: $text";
                }
            }
        }

        foreach ($this->literals()['___'] as $text => $file) {
            $slug = Str::slug($text, '_');
            if (! array_key_exists($slug, $en) || ! array_key_exists($slug, $ar)) {
                $problems[] = "[$file] ___( ) slug missing in en/ar lang.php: $slug <- $text";
                continue;
            }
            if ($ar[$slug] === $en[$slug]) {
                $problems[] = "[$file] ___( ) Arabic is still English: $slug ($text)";
            }
            if (! preg_match('/\p{Arabic}/u', $ar[$slug])) {
                $problems[] = "[$file] ___( ) Arabic has no Arabic characters: $slug ($text)";
            }
            foreach ($this->placeholders($en[$slug]) as $ph) {
                if (! str_contains($ar[$slug], $ph)) {
                    $problems[] = "[$file] ___( ) dropped $ph: $slug ($text)";
                }
            }
        }

        $this->assertSame([], $problems, 'Phone-agent strings missing an Arabic translation:'.PHP_EOL.implode(PHP_EOL, $problems));
    }

    public function test_required_phone_agent_slugs_are_translated_in_both_files(): void
    {
        $en = $this->langTable('en');
        $ar = $this->langTable('ar');
        $problems = [];

        foreach (self::REQUIRED as $slug) {
            if (! array_key_exists($slug, $en)) {
                $problems[] = "en missing: $slug";
                continue;
            }
            if (! array_key_exists($slug, $ar)) {
                $problems[] = "ar missing: $slug";
                continue;
            }
            if ($ar[$slug] === $en[$slug]) {
                $problems[] = "ar still English: $slug";
            }
            if (! preg_match('/\p{Arabic}/u', $ar[$slug])) {
                $problems[] = "ar has no Arabic characters: $slug";
            }
        }

        $this->assertSame([], $problems, 'Required phone-agent slugs miss an Arabic translation:'.PHP_EOL.implode(PHP_EOL, $problems));
    }

    public function test_en_and_ar_lang_share_identical_phone_agent_slugs(): void
    {
        $en = $this->langTable('en');
        $ar = $this->langTable('ar');
        $onlyEn = [];
        $onlyAr = [];

        foreach (array_keys($this->literals()['___']) as $text) {
            $slug = Str::slug($text, '_');
            if (array_key_exists($slug, $en) && ! array_key_exists($slug, $ar)) {
                $onlyEn[] = $slug;
            }
            if (! array_key_exists($slug, $en) && array_key_exists($slug, $ar)) {
                $onlyAr[] = $slug;
            }
        }

        $this->assertSame([], $onlyEn, 'Phone-agent slug present in en but absent in ar: '.implode(', ', $onlyEn));
        $this->assertSame([], $onlyAr, 'Phone-agent slug present in ar but absent in en: '.implode(', ', $onlyAr));
    }

    public function test_kds_phone_paid_badge_and_sms_pay_link_have_arabic(): void
    {
        $en = $this->langTable('en');
        $ar = $this->langTable('ar');
        $arJson = $this->arJson();

        // KDS badge resource/views/owner/kitchen/kds.blade.php — ___('Phone · paid').
        $slug = Str::slug('Phone · paid', '_');
        $this->assertSame('phone_paid', $slug);
        $this->assertArrayHasKey($slug, $en, "en missing $slug");
        $this->assertArrayHasKey($slug, $ar, "ar missing $slug");
        $this->assertNotSame($en[$slug], $ar[$slug], "'Phone · paid' badge Arabic is still English");
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar[$slug], "'Phone · paid' badge Arabic has no Arabic characters");

        // SMS pay-link string VoicePayLink::send() — __('Pay for your :store order #:order here: :url').
        $sms = 'Pay for your :store order #:order here: :url';
        $this->assertArrayHasKey($sms, $arJson, "SMS pay-link string not in lang/ar.json: $sms");
        $this->assertNotSame($sms, $arJson[$sms], 'SMS pay-link Arabic is still English');
        foreach ($this->placeholders($sms) as $ph) {
            $this->assertStringContainsString($ph, $arJson[$sms], "SMS pay-link dropped $ph");
        }
    }
}