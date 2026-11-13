<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Juwri\Modules\Menu\Services\Ai\MenuGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MenuGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.default'  => 'mimo',
            'ai.fallback' => [],
            'ai.vision'   => ['mimo'],
            'ai.retries'  => 0,
            'ai.providers.mimo' => [
                'driver' => 'openai', 'base_url' => 'https://api.xiaomimimo.com/v1',
                'api_key' => 'k', 'model' => 'm', 'supports_json' => true, 'supports_vision' => true,
            ],
        ]);
    }

    private function fakeMenu(array $json): void
    {
        Http::fake(['api.xiaomimimo.com/*' => Http::response([
            'choices' => [['message' => ['content' => json_encode($json)]]],
            'usage'   => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        ], 200)]);
    }

    public function test_parses_and_normalizes_a_menu(): void
    {
        $this->fakeMenu(['categories' => [
            ['name' => ' Starters ', 'items' => [
                ['name' => 'Soup',  'description' => 'Warm  bowl', 'price' => '$4.50'],
                ['name' => 'Salad', 'description' => '',           'price' => '12,50'],
                ['name' => '',      'description' => 'no name',    'price' => 5], // dropped
            ]],
            ['name' => '', 'items' => []], // dropped
        ]]);

        $draft = app(MenuGeneratorService::class)->fromText('irrelevant', 1, null);

        $this->assertCount(1, $draft['categories']);
        $cat = $draft['categories'][0];
        $this->assertSame('Starters', $cat['name']);
        $this->assertCount(2, $cat['items']);
        $this->assertSame('Soup', $cat['items'][0]['name']);
        $this->assertSame('Warm bowl', $cat['items'][0]['description']);
        $this->assertSame(4.50, $cat['items'][0]['price']);
        $this->assertSame(12.50, $cat['items'][1]['price']); // European comma decimal
    }

    public function test_missing_price_stays_null_not_invented(): void
    {
        $this->fakeMenu(['categories' => [
            ['name' => 'Drinks', 'items' => [['name' => 'Water', 'description' => '', 'price' => null]]],
        ]]);

        $draft = app(MenuGeneratorService::class)->fromText('x', 1, null);
        $this->assertNull($draft['categories'][0]['items'][0]['price']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('priceCases')]
    public function test_price_parser($raw, $expected): void
    {
        $this->assertSame($expected, app(MenuGeneratorService::class)->parsePrice($raw));
    }

    public static function priceCases(): array
    {
        return [
            ['$12.50', 12.50],
            ['12,50', 12.50],
            ['1.234,50', 1234.50],
            ['1,234.50', 1234.50],
            ['SR 15', 15.0],
            ['10-12', 10.0],
            ['', null],
            ['free', null],
            [7, 7.0],
        ];
    }
}
