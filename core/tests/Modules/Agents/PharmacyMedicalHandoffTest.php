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
use Juwri\Modules\Agents\Services\Agents\AgentSafety;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-46: a pharmacy's store assistant hands medical questions to the pharmacist (fixed reply, no model call). */
class PharmacyMedicalHandoffTest extends TestCase
{
    use RefreshDatabase;

    /** @var object{calls: int, script: list<array<string, mixed>>, idx: int, system: string} */
    private object $ai;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->ai = new class extends AiService
        {
            public int $calls = 0;

            /** @var list<array<string, mixed>> */
            public array $script = [];

            public int $idx = 0;

            public string $system = '';

            public function __construct() {}

            public function json(array $messages, array $options = [], ?callable $validator = null, ?int $userId = null, ?int $storeId = null, string $feature = 'general'): array
            {
                $this->calls++;
                $this->system = (string) ($messages[0]['content'] ?? '');

                return $this->script[$this->idx++] ?? ['reply' => 'Your order is on its way.'];
            }
        };
        app()->instance(AiService::class, $this->ai);
    }

    private function store(string $type, string $phone = '+971500000001'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'ai_chatbot' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'store_type' => $type, 'slug' => 'ph-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Care Pharmacy', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png',
            'address' => '1', 'phone' => $phone, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Pain relief', 'active' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Paracetamol 500mg', 'price' => 3, 'active' => 1]);

        return $store;
    }

    private function convo(Store $store): AgentConversation
    {
        return AgentConversation::create(['store_id' => $store->id, 'channel' => 'web', 'session_key' => 'sk'.uniqid()]);
    }

    public function test_detector_flags_medical_questions_and_leaves_order_questions_alone(): void
    {
        foreach ([
            'How many paracetamol should I take for a headache?', 'What is the dosage for a child?', 'Can I take ibuprofen with my blood thinners?',
            'Does this have side effects?', 'I am pregnant, is it safe to take?', 'I have a fever, what should I take for it?',
            'ما هي الجرعة المناسبة؟', 'Quelle est la posologie ?', '¿Puedo tomar esto con alcohol?', 'Welche Nebenwirkungen hat das?',
            'Yan etkileri var mı?', 'Какая дозировка?', '这个药的副作用是什么？', 'इसकी खुराक क्या है?', 'اس کی خوراک کیا ہے؟', 'Posso tomar com café?',
        ] as $q) {
            $this->assertTrue(AgentSafety::isMedicalQuestion($q), $q);
        }
        foreach ([
            'Where is my order?', 'Do you have paracetamol in stock?', 'How much is the vitamin C?', 'What time do you close?',
            'Can I pay by card on delivery?', 'I want 2 packs of plasters and a dozen masks', 'Cancel my order please', 'Is my payment secure?',
        ] as $q) {
            $this->assertFalse(AgentSafety::isMedicalQuestion($q), $q);
        }
    }

    public function test_a_medical_question_at_a_pharmacy_gets_the_fixed_hand_off_without_calling_the_model(): void
    {
        $store = $this->store('pharmacy');
        $c = $this->convo($store);

        $reply = AgentChatService::handle($c, $store, 'How many paracetamol should I take for a headache?');

        $this->assertSame(___('I can’t give medical advice. Please ask our pharmacist: call :phone or keep chatting here and a team member will reply.', ['phone' => '+971500000001']), $reply);
        $this->assertSame(0, $this->ai->calls, 'the model is never asked a medical question');
        $this->assertTrue((bool) ($c->fresh()->meta['handoff'] ?? false));
        $this->assertSame('pharmacist', $c->fresh()->meta['handoff_reason'] ?? null);
    }

    public function test_the_hand_off_without_a_store_phone_and_in_the_customers_language(): void
    {
        $store = $this->store('pharmacy', '');
        $this->assertSame(___('I can’t give medical advice. Please ask our pharmacist: a team member will reply here shortly.'), AgentChatService::handle($this->convo($store), $store, 'Does this have side effects?'));

        $arabic = AgentChatService::handle($this->convo($store), $store, 'ما هي الجرعة المناسبة؟');
        $this->assertStringContainsString('الصيدلي', $arabic);
        $this->assertSame('en', app()->getLocale(), 'the request locale is restored');
        $this->assertSame(0, $this->ai->calls);
    }

    public function test_order_questions_at_a_pharmacy_still_reach_the_assistant_with_the_no_advice_rule(): void
    {
        $store = $this->store('pharmacy');
        $reply = AgentChatService::handle($this->convo($store), $store, 'Where is my order?');

        $this->assertSame('Your order is on its way.', $reply);
        $this->assertSame(1, $this->ai->calls);
        $this->assertStringContainsString('NEVER give medical advice', $this->ai->system);
        $this->assertStringContainsString('+971500000001', $this->ai->system);
    }

    public function test_other_store_types_are_unaffected(): void
    {
        $store = $this->store('retail');
        $reply = AgentChatService::handle($this->convo($store), $store, 'What is the dosage for a child?');

        $this->assertSame('Your order is on its way.', $reply);
        $this->assertSame(1, $this->ai->calls);
        $this->assertStringNotContainsString('NEVER give medical advice', $this->ai->system);
    }
}
