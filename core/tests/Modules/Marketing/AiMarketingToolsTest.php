<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Marketing;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Ingredient;
use App\Models\MenuCategory;
use App\Models\RecipeItem;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Models\User;
use Juwri\Core\Support\CustomerText;
use Juwri\Modules\Agents\Services\Agents\AgentSafety;
use Juwri\Modules\Agents\Services\Agents\PiiRedactor;
use Juwri\Modules\Ai\Data\CopyLanguage;
use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Modules\Ai\Services\AiImageResult;
use Juwri\Modules\Ai\Services\AiLimitExceededException;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Juwri\Modules\Ai\Services\AiProviderInterface;
use Juwri\Modules\Ai\Services\AiResult;
use Juwri\Modules\Ai\Services\TranscriptionResult;
use Juwri\Modules\Integrations\Models\GbpReview;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-17 (J27-B): AI marketing tools — menu item description, social post draft, Google review reply draft.
 *
 * Runs through the REAL AiService (spend guard, monthly limit, ai_usage metering) with a fake provider in the chain:
 * no network call ever. Checks the prompt carries the right facts, AR/EN, one credit per call (one ai_usage row with
 * the tool's feature + the daily tenant meter), the existing limit message, store scoping, PII redaction and that
 * nothing is saved or posted.
 */
class AiMarketingToolsTest extends TestCase
{
    use RefreshDatabase;

    private FakeMarketingAiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['ai.monthly_call_limit' => 0, 'settings.admin_allow_gbp_link' => true]);
        $this->provider = new FakeMarketingAiProvider;
        $provider = $this->provider;
        $this->app->instance(AiProviderFactory::class, new class($provider) extends AiProviderFactory
        {
            public function __construct(private AiProviderInterface $fake) {}

            public function chain(?int $storeId = null, bool $needsVision = false): array
            {
                return [$this->fake];
            }

            public function hasByok(?int $storeId): bool
            {
                return false;
            }
        });
        $this->withoutMiddleware();
    }

    /** @return array{0: User, 1: Store} */
    private function ownerWithStore(string $title = 'Bait Al Mandi'): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => $title, 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);

        return [$owner, $store];
    }

    private function item(Store $store, string $name = 'Lamb Mandi', string $category = 'Rice dishes'): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => $category], ['parent' => null, 'position' => 1]);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name, 'description' => 'Old text',
            'price' => 45, 'image' => 'default.png', 'type' => 'nonveg', 'active' => '1', 'position' => 1,
            'is_halal' => 1, 'spice_level' => 2,
        ]);
    }

    private function review(Store $store, string $comment, int $stars = 2): GbpReview
    {
        Credential::firstOrCreate(['store_id' => $store->id, 'feature' => 'gbp'], [
            'provider' => 'gbp', 'active' => true,
            'secrets' => ['location_id' => 'LOC-1', 'account_id' => 'ACC-1', 'access_token' => 'AT'],
        ]);

        return GbpReview::create([
            'store_id' => $store->id, 'location_id' => 'LOC-1', 'external_id' => 'R'.Str::random(6),
            'reviewer_name' => 'Mariam Haddad', 'reviewer_is_anonymous' => false,
            'star_rating' => $stars, 'comment' => $comment, 'review_created_at' => now()->subDay(),
        ]);
    }

    private function usage(string $feature): int
    {
        return AiUsage::where('feature', $feature)->count();
    }

    // ---- menu item description ------------------------------------------------------------------------------

    public function test_item_description_prompt_has_the_item_facts_and_the_item_is_not_saved(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $item = $this->item($store);
        MenuExtra::create(['store_id' => $store->id, 'menu_id' => $item->id, 'title' => 'Garlic sauce', 'price' => 1]);
        $flour = Ingredient::create(['store_id' => $store->id, 'name' => 'Basmati rice', 'unit' => 'kg']);
        RecipeItem::create(['store_id' => $store->id, 'menu_id' => $item->id, 'ingredient_id' => $flour->id, 'quantity' => 0.3]);
        $this->provider->replies = ['{"description": "  Slow-cooked lamb on fragrant rice.  "}'];

        $res = $this->actingAs($owner)->postJson(route('stores.menu.aiDescribe', $store->id), [
            'item_id' => $item->id, 'notes' => 'our best seller', 'lang' => 'en',
        ])->assertOk();

        $res->assertJson(['success' => true, 'description' => 'Slow-cooked lamb on fragrant rice.', 'lang' => 'en', 'dir' => 'ltr']);
        [$system, $user] = $this->provider->lastMessages();
        $this->assertSame('system', $system['role']);
        $this->assertStringContainsString('Write the text in English.', $system['content']);
        $this->assertStringContainsString('Name: Lamb Mandi', $user['content']);
        $this->assertStringContainsString('Category: Rice dishes', $user['content']);
        $this->assertStringContainsString('Basmati rice', $user['content']);
        $this->assertStringContainsString('Garlic sauce', $user['content']);
        $this->assertStringContainsString('halal', $user['content']);
        $this->assertStringContainsString('spice level 2 of 3', $user['content']);
        $this->assertStringContainsString('Owner notes: our best seller', $user['content']);
        // Owner text stays in the data (user) message, never in the system prompt.
        $this->assertStringNotContainsString('best seller', $system['content']);
        // Draft only: the item is untouched.
        $this->assertSame('Old text', $item->fresh()->getRawOriginal('description'));
    }

    public function test_item_description_in_arabic_and_from_an_unsaved_form(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'مشويات', 'parent' => null, 'position' => 1]);
        $this->provider->replies = ['{"description": "شيش طاووق متبل ومشوي على الفحم."}'];

        $this->actingAs($owner)->postJson(route('stores.menu.aiDescribe', $store->id), [
            'name' => 'شيش طاووق', 'category_id' => $cat->id, 'lang' => 'ar',
        ])->assertOk()->assertJson(['description' => 'شيش طاووق متبل ومشوي على الفحم.', 'lang' => 'ar', 'dir' => 'rtl']);

        [$system, $user] = $this->provider->lastMessages();
        $this->assertStringContainsString('Write the text in Arabic', $system['content']);
        $this->assertStringContainsString('Name: شيش طاووق', $user['content']);
        $this->assertStringContainsString('Category: مشويات', $user['content']);
    }

    public function test_language_defaults_to_the_store_language(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        StoreOption::updateStoreOption($store->id, 'default_language', 'ar');
        $this->provider->replies = ['{"description": "نص"}'];

        $this->actingAs($owner)->postJson(route('stores.menu.aiDescribe', $store->id), ['name' => 'Falafel'])
            ->assertOk()->assertJson(['lang' => 'ar', 'dir' => 'rtl']);
        $this->assertSame('ar', CopyLanguage::storeDefault((int) $store->id)->code);
        $this->assertSame('en', CopyLanguage::pick('en', (int) $store->id)->code);
        $this->assertSame('ar', CopyLanguage::pick('xx', (int) $store->id)->code);
    }

    public function test_item_description_is_store_scoped(): void
    {
        [$ownerA, $storeA] = $this->ownerWithStore('A');
        [, $storeB] = $this->ownerWithStore('B');
        $itemB = $this->item($storeB, 'Secret dish');

        // Another owner's store: refused.
        $this->actingAs($ownerA)->postJson(route('stores.menu.aiDescribe', $storeB->id), ['item_id' => $itemB->id])->assertForbidden();
        // Own store, but another store's item: not found.
        $this->actingAs($ownerA)->postJson(route('stores.menu.aiDescribe', $storeA->id), ['item_id' => $itemB->id])->assertNotFound();

        $this->assertSame([], $this->provider->calls);
        $this->assertSame(0, AiUsage::count());
    }

    // ---- social post ----------------------------------------------------------------------------------------

    public function test_social_post_prompt_has_item_and_offer_facts_and_returns_text_plus_hashtags(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        $item = $this->item($store);
        $this->provider->replies = ['{"text": "Weekend treat! **Lamb Mandi** for the family. #yum Order now.", "hashtags": ["#Mandi", "Riyadh Food", "#mandi", "#الرياض"]}'];

        $res = $this->actingAs($owner)->postJson(route('stores.marketing.socialPost.draft').'?store_id='.$store->id, [
            'item_id' => $item->id, 'offer' => '20% off this weekend', 'lang' => 'en',
        ])->assertOk();

        $res->assertJson(['success' => true, 'lang' => 'en', 'dir' => 'ltr']);
        $this->assertSame('Weekend treat! Lamb Mandi for the family. Order now.', $res->json('text'));
        $this->assertSame(['#Mandi', '#RiyadhFood', '#الرياض'], $res->json('hashtags'));
        [$system, $user] = $this->provider->lastMessages();
        $this->assertStringContainsString('Write the text in English.', $system['content']);
        $this->assertStringContainsString('Store: Bait Al Mandi', $user['content']);
        $this->assertStringContainsString('Item: Lamb Mandi', $user['content']);
        $this->assertStringContainsString('Price: 45', $user['content']);
        $this->assertStringContainsString('SAR', $user['content']);
        $this->assertStringContainsString('Offer or news from the owner: 20% off this weekend', $user['content']);
        $this->assertStringNotContainsString('20% off', $system['content']);
    }

    public function test_social_post_in_arabic_from_an_offer_only(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $this->provider->replies = ['{"text": "عرض نهاية الأسبوع!", "hashtags": ["#عروض"]}'];

        $this->actingAs($owner)->postJson(route('stores.marketing.socialPost.draft').'?store_id='.$store->id, [
            'offer' => 'خصم 20٪ على المندي', 'lang' => 'ar',
        ])->assertOk()->assertJson(['text' => 'عرض نهاية الأسبوع!', 'hashtags' => ['#عروض'], 'lang' => 'ar', 'dir' => 'rtl']);

        [$system, $user] = $this->provider->lastMessages();
        $this->assertStringContainsString('Write the text in Arabic', $system['content']);
        $this->assertStringContainsString('خصم 20٪ على المندي', $user['content']);
    }

    public function test_social_post_needs_an_item_or_an_offer(): void
    {
        [$owner, $store] = $this->ownerWithStore();

        $this->actingAs($owner)->postJson(route('stores.marketing.socialPost.draft').'?store_id='.$store->id, ['lang' => 'en'])
            ->assertStatus(422);
        $this->assertSame([], $this->provider->calls);
    }

    public function test_social_post_is_store_scoped(): void
    {
        [$ownerA, $storeA] = $this->ownerWithStore('A');
        [, $storeB] = $this->ownerWithStore('B');
        $itemB = $this->item($storeB, 'Secret dish');

        $this->actingAs($ownerA)->postJson(route('stores.marketing.socialPost.draft').'?store_id='.$storeB->id, ['offer' => 'x'])->assertForbidden();
        $this->actingAs($ownerA)->postJson(route('stores.marketing.socialPost.draft').'?store_id='.$storeA->id, ['item_id' => $itemB->id])->assertNotFound();
        $this->actingAs($ownerA)->get(route('stores.marketing.socialPost.index').'?store_id='.$storeB->id)->assertForbidden();

        $this->assertSame([], $this->provider->calls);
        $this->assertSame(0, AiUsage::count());
    }

    public function test_social_post_page_renders_items_and_language_pick(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $this->item($store, 'Kunafa');
        $this->shareLayoutGlobals();

        $res = $this->actingAs($owner)->get(route('stores.marketing.socialPost.index').'?store_id='.$store->id)->assertOk();
        $res->assertSee('Social post writer');
        $res->assertSee('Kunafa');
        $res->assertSee('العربية');
        $res->assertSee('data-social-form', false);
        $res->assertSee('nonce=', false);
    }

    // ---- Google review reply --------------------------------------------------------------------------------

    public function test_review_reply_redacts_pii_and_never_sends_the_reviewer_name(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $review = $this->review($store, 'Cold food. Call me on +966 55 123 4567 or mail mariam@example.com, card 4111 1111 1111 1111 was charged twice.', 1);
        $this->provider->replies = ['{"reply": "We are sorry about your visit. Please contact the store so we can make it right."}'];

        $res = $this->actingAs($owner)->postJson(route('stores.gbp.reviews.draftReply').'?store_id='.$store->id, [
            'review_id' => $review->id, 'lang' => 'en',
        ])->assertOk();

        $res->assertJson(['success' => true, 'reply' => 'We are sorry about your visit. Please contact the store so we can make it right.', 'dir' => 'ltr']);
        [$system, $user] = $this->provider->lastMessages();
        $this->assertStringContainsString('Rating: 1 out of 5', $user['content']);
        $this->assertStringContainsString('Cold food.', $user['content']);
        $this->assertStringContainsString('[phone]', $user['content']);
        $this->assertStringContainsString('[email]', $user['content']);
        $this->assertStringContainsString('[card]', $user['content']);
        $this->assertStringNotContainsString('123 4567', $user['content']);
        $this->assertStringNotContainsString('mariam@example.com', $user['content']);
        $this->assertStringNotContainsString('4111', $user['content']);
        foreach ([$system['content'], $user['content']] as $part) {
            $this->assertStringNotContainsString('Mariam', $part);
            $this->assertStringNotContainsString('Haddad', $part);
        }
        // Draft only: nothing posted, nothing stored on the review.
        $this->assertNull($review->fresh()->reply_comment);
    }

    public function test_review_reply_in_arabic(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $review = $this->review($store, 'الأكل لذيذ والخدمة سريعة', 5);
        $this->provider->replies = ['{"reply": "شكرًا لك على كلماتك الجميلة، ننتظرك مرة أخرى!"}'];

        $this->actingAs($owner)->postJson(route('stores.gbp.reviews.draftReply').'?store_id='.$store->id, [
            'review_id' => $review->id, 'lang' => 'ar',
        ])->assertOk()->assertJson(['reply' => 'شكرًا لك على كلماتك الجميلة، ننتظرك مرة أخرى!', 'lang' => 'ar', 'dir' => 'rtl']);

        [$system, $user] = $this->provider->lastMessages();
        $this->assertStringContainsString('Write the text in Arabic', $system['content']);
        $this->assertStringContainsString('الأكل لذيذ والخدمة سريعة', $user['content']);
        $this->assertStringContainsString('Rating: 5 out of 5', $user['content']);
    }

    public function test_abusive_review_is_refused_before_any_ai_call(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $review = $this->review($store, 'this place is shit', 1);

        $this->actingAs($owner)->postJson(route('stores.gbp.reviews.draftReply').'?store_id='.$store->id, ['review_id' => $review->id])
            ->assertStatus(422)->assertJsonFragment(['error' => 'This review looks like spam or abuse, so no AI draft was made. Write your reply yourself or report the review to Google.']);

        $this->assertSame([], $this->provider->calls);
        $this->assertSame(0, AiUsage::count());
    }

    public function test_review_reply_is_store_scoped(): void
    {
        [$ownerA, $storeA] = $this->ownerWithStore('A');
        [, $storeB] = $this->ownerWithStore('B');
        $reviewB = $this->review($storeB, 'Nice');

        $this->actingAs($ownerA)->postJson(route('stores.gbp.reviews.draftReply').'?store_id='.$storeB->id, ['review_id' => $reviewB->id])->assertForbidden();
        $this->actingAs($ownerA)->postJson(route('stores.gbp.reviews.draftReply').'?store_id='.$storeA->id, ['review_id' => $reviewB->id])->assertNotFound();

        $this->assertSame([], $this->provider->calls);
        $this->assertSame(0, AiUsage::count());
    }

    // ---- credits, limits, gates -----------------------------------------------------------------------------

    public function test_each_tool_call_costs_one_credit_like_every_ai_call(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $item = $this->item($store);
        $review = $this->review($store, 'Great');
        $this->provider->replies = [
            '{"description": "d"}',
            '{"text": "t", "hashtags": []}',
            '{"reply": "r"}',
            '{"description": "d2"}',
        ];

        $this->actingAs($owner)->postJson(route('stores.menu.aiDescribe', $store->id), ['item_id' => $item->id])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.marketing.socialPost.draft').'?store_id='.$store->id, ['item_id' => $item->id])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.gbp.reviews.draftReply').'?store_id='.$store->id, ['review_id' => $review->id])->assertOk();
        $this->actingAs($owner)->postJson(route('stores.menu.aiDescribe', $store->id), ['item_id' => $item->id])->assertOk();

        $this->assertSame(2, $this->usage('menu_description'));
        $this->assertSame(1, $this->usage('social_post'));
        $this->assertSame(1, $this->usage('review_reply'));
        $this->assertSame(4, AiUsage::where('user_id', $owner->id)->count());
        // Same per-call rule as AiService for every feature: one row per call, the fake's tokens recorded.
        $row = AiUsage::where('feature', 'review_reply')->first();
        $this->assertSame('fake', $row->provider);
        $this->assertSame(10, (int) $row->prompt_tokens);
        $this->assertSame(5, (int) $row->completion_tokens);
        $this->assertSame(4, (int) TenantUsageDaily::where('user_id', $owner->id)->where('metric', 'ai_credits')->sum('value'));
    }

    public function test_monthly_limit_reached_refuses_every_tool_with_the_existing_message(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $item = $this->item($store);
        $review = $this->review($store, 'Great');
        config(['ai.monthly_call_limit' => 2]);
        foreach ([1, 2] as $i) {
            AiUsage::create(['user_id' => $owner->id, 'provider' => 'x', 'model' => 'x', 'feature' => 'general', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'fell_back' => false]);
        }
        $message = (new AiLimitExceededException)->getMessage();

        $this->actingAs($owner)->postJson(route('stores.menu.aiDescribe', $store->id), ['item_id' => $item->id])
            ->assertStatus(429)->assertJson(['error' => $message]);
        $this->actingAs($owner)->postJson(route('stores.marketing.socialPost.draft').'?store_id='.$store->id, ['item_id' => $item->id])
            ->assertStatus(429)->assertJson(['error' => $message]);
        $this->actingAs($owner)->postJson(route('stores.gbp.reviews.draftReply').'?store_id='.$store->id, ['review_id' => $review->id])
            ->assertStatus(429)->assertJson(['error' => $message]);

        $this->assertSame([], $this->provider->calls);
        $this->assertSame(2, AiUsage::count());
    }

    public function test_daily_spend_cap_refuses_too(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        config(['settings.abuse_ai_spend_mode' => 'enforce', 'settings.abuse_ai_spend_user_daily_cap' => 1]);
        AiUsage::create(['user_id' => $owner->id, 'provider' => 'x', 'model' => 'x', 'feature' => 'general', 'prompt_tokens' => 1, 'completion_tokens' => 1, 'fell_back' => false]);

        $this->actingAs($owner)->postJson(route('stores.menu.aiDescribe', $store->id), ['name' => 'Tea'])
            ->assertStatus(429)->assertJson(['error' => (new AiLimitExceededException)->getMessage()]);
        $this->assertSame([], $this->provider->calls);
    }

    public function test_provider_failure_gives_a_friendly_error_and_no_credit(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $this->provider->fail = true;

        $this->actingAs($owner)->postJson(route('stores.menu.aiDescribe', $store->id), ['name' => 'Tea'])
            ->assertStatus(502)->assertJson(['error' => 'The AI service is unavailable right now. Please try again in a moment.']);
        $this->assertSame(0, AiUsage::count());
    }

    public function test_routes_carry_the_ai_rate_limit_and_the_reviews_screen_gate(): void
    {
        foreach (['stores.menu.aiDescribe', 'stores.marketing.socialPost.draft', 'stores.gbp.reviews.draftReply'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('ratelimit:ai', $middleware, $name);
            $this->assertContains('auth', $middleware, $name);
        }
        $this->assertContains('feature:gbp_link', Route::getRoutes()->getByName('stores.gbp.reviews.draftReply')->gatherMiddleware());
    }

    public function test_full_middleware_stack_serves_the_owner_and_refuses_guests(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        $item = $this->item($store);
        $review = $this->review($store, 'Lovely');
        $this->provider->replies = ['{"description": "d"}', '{"text": "t", "hashtags": ["#a"]}', '{"reply": "r"}'];
        config(['app.installed' => true]);
        $this->withMiddleware();

        $this->postJson(route('stores.menu.aiDescribe', $store->id), ['item_id' => $item->id])->assertUnauthorized();
        $this->actingAs($owner)->postJson(route('stores.menu.aiDescribe', $store->id), ['item_id' => $item->id])
            ->assertOk()->assertJson(['description' => 'd']);
        $this->actingAs($owner)->postJson(route('stores.marketing.socialPost.draft').'?store_id='.$store->id, ['item_id' => $item->id])
            ->assertOk()->assertJson(['text' => 't']);
        $this->actingAs($owner)->postJson(route('stores.gbp.reviews.draftReply').'?store_id='.$store->id, ['review_id' => $review->id])
            ->assertOk()->assertJson(['reply' => 'r']);
        $this->assertSame(3, AiUsage::where('user_id', $owner->id)->count());
    }

    public function test_menu_item_dialog_and_reviews_inbox_show_the_ai_draft_controls(): void
    {
        [$owner, $store] = $this->ownerWithStore();
        StoreOption::updateStoreOption($store->id, 'default_language', 'ar');
        $this->item($store);
        $this->review($store, 'Lovely');
        $this->shareLayoutGlobals();
        $this->withMiddleware();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);

        $menu = $this->actingAs($owner)->get(route('stores.menu', $store->id))->assertOk()->assertViewIs('owner.menu.index');
        $menu->assertSee('Write with AI');
        $menu->assertSee('aiDescribe()', false);
        $menu->assertSee(str_replace('/', '\\/', route('stores.menu.aiDescribe', $store->id)), false); // @js() escapes slashes
        $menu->assertSee('<option value="ar" lang="ar" selected>', false); // store language preselected
        $menu->assertSee('name="description" dir="auto"', false);

        $inbox = $this->actingAs($owner)->get(route('stores.gbp.reviews.index').'?store_id='.$store->id)->assertOk();
        $inbox->assertSee('Draft with AI');
        $inbox->assertSee('data-gbp-ai-draft', false);
        $inbox->assertSee('data-gbp-reply-text', false);
        $inbox->assertSee('<option value="ar" lang="ar" selected>', false);
        $this->assertMatchesRegularExpression('/<script nonce="[^"]+">\s*\(function \(\) \{\s*\'use strict\';\s*\/\/ P1-17/', $inbox->getContent());
    }

    // ---- shared helpers + strings ---------------------------------------------------------------------------

    public function test_agents_helpers_still_behave_the_same_through_customer_text(): void
    {
        $text = 'Mail a@b.co or call +1 (555) 123-4567, card 4111-1111-1111-1111';
        $this->assertSame(CustomerText::redactPii($text), PiiRedactor::redact($text));
        $this->assertSame('Mail [email] or call [phone], card [card]', PiiRedactor::redact($text));
        $this->assertTrue(AgentSafety::isAbusive('aaaaaaaaaaaaaaaaaaaa'));
        $this->assertTrue(CustomerText::isAbusive('you bastard'));
        $this->assertFalse(AgentSafety::isAbusive('The lamb was lovely'));
    }

    public function test_new_strings_are_translated_in_every_locale(): void
    {
        $strings = [
            'Write with AI', 'Draft with AI', 'Social post writer', 'Write post', 'Hashtags', 'Copy post',
            'Enter the item name first.', 'The AI service is unavailable right now. Please try again in a moment.',
            'This review looks like spam or abuse, so no AI draft was made. Write your reply yourself or report the review to Google.',
            'Notes for the AI (optional), e.g. best seller, served hot', 'Notes for the AI (optional)',
            'The AI writes a draft from the item details. Check it, then save the item.',
            'Draft ready. Check it, then save the item.', 'Draft ready. Check it, then send your reply.',
            'Let AI draft a post about a dish or an offer. Nothing is posted: you copy it and share it yourself.',
            'What is the post about?', 'No item, just the offer below', 'Offer or news (optional)',
            'e.g. 20% off all burgers this weekend', 'The AI only uses what you give it: the item details and this text.',
            'Your draft', 'Post text', 'Edit the draft as you like. Nothing is posted for you.',
            'Pick a menu item or write an offer first.', 'Draft ready.',
        ];
        foreach (['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $locale) {
            $lines = require lang_path($locale.'/lang.php');
            foreach ($strings as $text) {
                $key = Str::slug($text, '_');
                $this->assertArrayHasKey($key, $lines, "$locale: $text");
                if ($locale === 'en') {
                    $this->assertSame($text, $lines[$key]);
                } else {
                    $this->assertNotSame($text, $lines[$key], "$locale still English: $text");
                }
            }
        }
        $ar = require lang_path('ar/lang.php');
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $ar[Str::slug('Write with AI', '_')]);
    }
}

/** A provider that never touches the network: returns queued replies and records what it was sent. */
final class FakeMarketingAiProvider implements AiProviderInterface
{
    /** @var list<string> */
    public array $replies = ['{}'];

    /** @var list<array<int, array<string, mixed>>> */
    public array $calls = [];

    public bool $fail = false;

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function chat(array $messages, array $options = []): AiResult
    {
        $this->calls[] = $messages;
        if ($this->fail) {
            throw new AiProviderException('fake outage', 'fake', false);
        }
        $text = count($this->replies) > 1 ? array_shift($this->replies) : ($this->replies[0] ?? '{}');

        return new AiResult(text: $text, provider: 'fake', model: 'fake-1', promptTokens: 10, completionTokens: 5);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    public function lastMessages(): array
    {
        $last = end($this->calls) ?: [];

        return [$last[0] ?? [], $last[1] ?? []];
    }

    public function supportsImageGeneration(): bool
    {
        return false;
    }

    public function supportsTranscription(): bool
    {
        return false;
    }

    public function transcribe(string $audioPath, array $options = []): TranscriptionResult
    {
        throw new AiProviderException('not supported', 'fake', false);
    }

    public function generateImage(string $prompt, array $options = []): AiImageResult
    {
        throw new AiProviderException('not supported', 'fake', false);
    }
}
