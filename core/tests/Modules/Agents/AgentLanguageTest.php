<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Models\MenuCategory;
use App\Services\Agents\AgentChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Services\Agents\AgentLanguage;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Menu\Models\Menu;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** Agents AO10 #50: multilingual auto-detect + reply-in-language. */
class AgentLanguageTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('languageCases')]
    public function test_detect_language(string $text, string $expected): void
    {
        $this->assertSame($expected, AgentLanguage::detect($text));
    }

    public static function languageCases(): array
    {
        return [
            'arabic' => ['هل لديكم بيتزا؟', 'ar'],
            'russian' => ['У вас есть пицца?', 'ru'],
            'chinese' => ['你们有披萨吗', 'zh'],
            'hindi' => ['क्या आपके पास पिज़्ज़ा है', 'hi'],
            'hebrew' => ['יש לכם פיצה', 'he'],
            'persian' => ['چند تا پیتزا میخواهم', 'fa'],   // uses pe/che + farsi yeh → not Urdu
            'urdu' => ['کیا آپ کے پاس پیزا ہے', 'ur'],   // Urdu-exclusive ہ/ے/آپ
            'latin_fallback' => ['do you have pizza', 'en'],   // Latin script → fallback
            'digits_only' => ['12345 !!!', 'en'],           // no letters → fallback
        ];
    }

    public function test_fallback_is_respected_for_latin(): void
    {
        $this->assertSame('fr', AgentLanguage::detect('avez-vous des pizzas', 'fr'));
    }

    // --- integration: the chat loop pins the conversation to the detected language ---

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'ai_chatbot' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'Diner', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'M', 'active' => 1]);
        Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);

        return $r;
    }

    public function test_system_prompt_names_the_language(): void
    {
        $r = $this->storeFixture();
        $prompt = AgentChatService::systemPrompt($r, 'ar');
        $this->assertStringContainsString('Arabic', $prompt);
        // No language passed → no reply-in-language clause.
        $this->assertStringNotContainsString('the customer\'s language', AgentChatService::systemPrompt($r));
    }

    public function test_chat_pins_conversation_to_detected_language(): void
    {
        $r = $this->storeFixture();
        $fake = new class extends AiService
        {
            public function __construct() {}

            public function json(array $messages, array $options = [], ?callable $validator = null, ?int $userId = null, ?int $storeId = null, string $feature = 'general'): array
            {
                return ['reply' => 'مرحبا'];
            }
        };
        app()->instance(AiService::class, $fake);

        AgentChatService::forWhatsApp($r->id, '+15551230000', 'هل لديكم بيتزا؟');

        $conv = AgentConversation::where('store_id', $r->id)->first();
        $this->assertSame('ar', $conv->locale); // pinned to the detected language
    }
}
