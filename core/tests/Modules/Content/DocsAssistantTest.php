<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Content\Services\Docs\DocsAssistantService;
use Tests\TestCase;

/**
 * DOCS-PLATFORM D6-3: the docs-grounded assistant. Ships dark (404 while off);
 * when on, answers from docs only and cites a subset of the retrieved pages.
 */
class DocsAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    private function fakeAi(array $reply): void
    {
        $fake = new class($reply) extends AiService
        {
            public function __construct(public array $reply) {}

            public function json(array $messages, array $options = [], ?callable $validator = null, ?int $userId = null, ?int $storeId = null, string $feature = 'general'): array
            {
                return $this->reply;
            }
        };
        app()->instance(AiService::class, $fake);
    }

    public function test_endpoint_is_404_while_the_flag_is_off(): void
    {
        config(['settings.docs_assistant_enabled' => false]);
        $this->postJson('/help-center/ask', ['q' => 'how do I add a coupon?'])->assertNotFound();
        // and the Ask box is absent from the index
        $this->get('/help-center')->assertOk()->assertDontSeeHtml('Ask the guides');
    }

    public function test_when_enabled_it_answers_and_keeps_only_allowed_sources(): void
    {
        config(['settings.docs_assistant_enabled' => true]);
        // The model returns a real slug (coupons) and a hallucinated one (made-up)
        // — only the retrieved-and-allowed one survives.
        $this->fakeAi(['answer' => 'Open Coupons and click Add.', 'sources' => ['coupons', 'made-up-page']]);

        $res = $this->postJson('/help-center/ask', ['q' => 'how do I create a coupon code?']);
        $res->assertOk();
        $res->assertJsonPath('answer', 'Open Coupons and click Add.');
        $slugs = array_column($res->json('sources'), 'slug');
        $this->assertContains('coupons', $slugs);
        $this->assertNotContains('made-up-page', $slugs);

        $this->get('/help-center')->assertOk()->assertSeeHtml('Ask the guides');
    }

    public function test_validation_rejects_an_empty_question(): void
    {
        config(['settings.docs_assistant_enabled' => true]);
        $this->postJson('/help-center/ask', ['q' => ''])->assertStatus(422);
    }

    public function test_service_defaults_to_disabled(): void
    {
        config(['settings.docs_assistant_enabled' => null]);
        $this->assertFalse(DocsAssistantService::enabled());
    }
}
