<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Juwri\Modules\Agents\Services\Telephony\PciDigitFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** P6-09 V-09: card digits are cut from speech-to-text text; phone numbers and order totals survive. */
class PciDigitFilterTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function cardUtterances(): array
    {
        return [
            'western grouped' => ['my number is 4242 4242 4242 4242 thanks'],
            'western dashed' => ['4111-1111-1111-1111'],
            'western 13' => ['take 4222222222222 please'],
            'arabic-indic' => ['رقمها ٤٢٤٢ ٤٢٤٢ ٤٢٤٢ ٤٢٤٢'],
            'persian digits' => ['۴۲۴۲۴۲۴۲۴۲۴۲۴۲۴۲'],
            'spoken en' => ['four two four two four two four two four two four two four two four two'],
            'spoken en double' => ['double four double two double four double two four two four two four two'],
            'spoken ar' => ['أربعة اثنين أربعة اثنين أربعة اثنين أربعة اثنين أربعة اثنين أربعة اثنين أربعة'],
            'spoken ar with waw' => ['اربعة واثنين واربعة واثنين واربعة واثنين واربعة واثنين واربعة واثنين واربعة واثنين واربعة'],
            'mixed spoken and digits' => ['4242 four two four two 4242 4242'],
            'cvv with card context' => ['the security code on my card is seven three one'],
            'expiry with card context' => ['visa expires 04/27'],
            'arabic cvv with context' => ['رمز البطاقة ثلاثة سبعة واحد'],
        ];
    }

    #[DataProvider('cardUtterances')]
    public function test_card_digits_are_redacted(string $utterance): void
    {
        $r = PciDigitFilter::filter($utterance);

        $this->assertTrue($r['redacted'], $utterance);
        $this->assertStringContainsString(PciDigitFilter::MARK, $r['text']);
        $this->assertDoesNotMatchRegularExpression('/\d{3}/', $r['text'], 'no digit run survives: '.$r['text']);
        $this->assertDoesNotMatchRegularExpression('/(four|two|seven|three|اربعة|أربعة|اثنين|ثلاثة|سبعة)/u', $r['text'], 'no spoken digit survives: '.$r['text']);
    }

    /** @return array<string, array{string}> */
    public static function safeUtterances(): array
    {
        return [
            'ksa mobile' => ['call me back on +966 50 123 4567'],
            'local mobile' => ['my number is 0501234567'],
            'spoken phone' => ['zero five zero one two three four five six seven'],
            'arabic-indic phone' => ['رقمي ٠٥٠١٢٣٤٥٦٧'],
            'order total' => ['so the total is 125.50 riyals for 3 pizzas'],
            'spoken total' => ['two hundred and forty five riyals'],
            'quantities' => ['two shawarma, one large fries and 4 cokes'],
            'card word, no digits' => ['can I pay by card when it arrives?'],
            'card word, short number' => ['I will pay by card, order for 2 people'],
        ];
    }

    #[DataProvider('safeUtterances')]
    public function test_phone_numbers_totals_and_quantities_are_kept(string $utterance): void
    {
        $r = PciDigitFilter::filter($utterance);

        $this->assertFalse($r['redacted'], $utterance.' → '.$r['text']);
        $this->assertStringNotContainsString(PciDigitFilter::MARK, $r['text']);
    }

    public function test_text_around_the_run_is_kept_and_adjacent_runs_merge(): void
    {
        $r = PciDigitFilter::filter('my card 4242 4242 4242 4242, exp 12/29. A pizza please');

        $this->assertSame('my card [card], exp [card]. A pizza please', $r['text']);
    }

    public function test_an_already_redacted_text_still_counts_as_redacted(): void
    {
        $this->assertTrue(PciDigitFilter::filter('it is [card]')['redacted']);
        $this->assertSame(['text' => '', 'redacted' => false], PciDigitFilter::filter(''));
    }
}
