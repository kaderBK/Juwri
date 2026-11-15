<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\User;

use App\Http\Controllers\User\Concerns\HandlesStorefrontQrScan;
use App\Http\Requests\StoreSettingsRules;
use App\Mails\StoreOrderMail;
use App\Models\Cuisine;
use App\Models\Customer;
use App\Models\MenuBanner;
use App\Models\MenuCategory;
use App\Models\MenuReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RecipeItem;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Models\StoreView;
use App\Services\CouponService;
use App\Services\Entitlements;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\GiftCardService;
use App\Services\GuestOrderSession;
use App\Services\InventoryService;
use App\Services\KdsService;
use App\Services\Loyalty\LoyaltyAccrual;
use App\Services\OrderApiService;
use App\Services\OrderIntakeGate;
use App\Services\PlatformMetrics;
use App\Services\StoreCloneService;
use App\Services\Storefront\LegacyCartLines;
use App\Services\Storefront\StorefrontMenuCache;
use App\Services\StorefrontThemeService;
use App\Services\StorefrontToggleMask;
use App\Services\Theme\VerticalThemeSets;
use App\Services\ThemeCustomizerService;
use App\Services\ThemePurchaseService;
use App\Services\Verticals\VerticalRegistry;
use App\Services\WebhookService;
use App\Services\WhatsApp\OrderMessage;
use App\Storefront\StorefrontExtras;
use App\Support\KioskContext;
use App\Support\OrderCreatedWebhookPayload;
use App\Support\OutboundText;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Editions\Limits\StoreLimitGuard;
use Juwri\Core\Http\Controllers\Concerns\ScopesToBranch;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Controllers\User\Concerns\HandlesExternalRedirect;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Money;
use Juwri\Core\Rules\GloballyUnique;
use Juwri\Core\Rules\PhishingSlug;
use Juwri\Core\Rules\ReservedSlug;
use Juwri\Core\Services\Store\StoreSettingsService;
use Juwri\Core\Services\Tenancy\GlobalSlugService;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Core\Support\ClientOrderConflict;
use Juwri\Core\Support\ClientOrderId;
use Juwri\Core\Support\PlanGate;
use Juwri\Core\Support\SafeUrl;
use Juwri\Modules\Analytics\Http\Controllers\User\Concerns\ReportsValidationFailures;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Juwri\Modules\Content\Services\Studio\StudioDraftImporter;
use Juwri\Modules\Customers\Models\CustomerFeedback;
use Juwri\Modules\Customers\Models\NpsResponse;
use Juwri\Modules\Customers\Services\FeedbackService;
use Juwri\Modules\Integrations\Jobs\SendMetaCapiEventJob;
use Juwri\Modules\Kitchen\Services\OrderBoard\OrderBoardService;
use Juwri\Modules\Menu\Http\Controllers\User\Concerns\GeneratesMenuWithAi;
use Juwri\Modules\Menu\Http\Controllers\User\Concerns\ImportsMenuCsv;
use Juwri\Modules\Menu\Http\Controllers\User\Concerns\ManagesMenu;
use Juwri\Modules\Menu\Models\Allergy;
use Juwri\Modules\Menu\Models\BranchMenu;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuItemEvent;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Juwri\Modules\Menu\Services\Combos\ComboService;
use Juwri\Modules\Menu\Services\MenuAdminService;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\WhatsApp;
use Juwri\Modules\Messaging\Models\SmsOptout;
use Juwri\Modules\Messaging\Models\WhatsAppConsent;
use Juwri\Modules\Ordering\Contracts\DeliverySlotBook;
use Juwri\Modules\Ordering\Contracts\SalesRestrictions;
use Juwri\Modules\Ordering\Http\Controllers\CheckoutOtpController;
use Juwri\Modules\Ordering\Http\Controllers\User\Concerns\ManagesOrders;
use Juwri\Modules\Ordering\Http\Controllers\User\VoiceNoteController;
use Juwri\Modules\Ordering\Jobs\TranscribeVoiceNote;
use Juwri\Modules\Ordering\Models\CustomerPushSubscription;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Ordering\Models\OrderVoiceNote;
use Juwri\Modules\Ordering\Services\CustomerPushService;
use Juwri\Modules\Ordering\Services\Orders\EtaEstimator;
use Juwri\Modules\Ordering\Services\Orders\NewOrderAlert;
use Juwri\Modules\Ordering\Services\Orders\OrderPricing;
use Juwri\Modules\Ordering\Services\Orders\OrderTextRenderer;
use Juwri\Modules\Ordering\Services\Orders\SchedulingService;
use Juwri\Modules\Ordering\Services\Tax\TaxCountryRules;
use Juwri\Modules\Ordering\Services\Tax\TaxEngine;
use Juwri\Modules\Ordering\Services\Tax\TaxLevies;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Pos\Services\Kiosk\KioskLoyalty;
use Juwri\Modules\Storefront\Models\SlugRedirect;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderFailedException;
use Juwri\Modules\Storefront\Services\Storefront\FoodCatalogPaging;
use Juwri\Modules\Storefront\Services\Storefront\KitStorefront;
use Juwri\Modules\Storefront\Services\Storefront\OfflineQueuePolicy;
use Juwri\Modules\Storefront\Services\Storefront\StorefrontCheckoutAdapter;
use Juwri\Modules\Storefront\Services\Theme\KitThemes;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\ShellStyles;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Juwri\Modules\Storefront\Storefront\ThemeSettingValues;
use Juwri\Modules\Tables\Http\Controllers\User\Concerns\BuildsQrCodes;
use Juwri\Modules\Tables\Http\Controllers\User\Concerns\ManagesStoreTables;
use Juwri\Modules\Tables\Http\Middleware\EnsureTableSessionVerified;
use Juwri\Modules\Tables\Models\WaiterCall;
use Juwri\Modules\Tables\Services\TableMergeService;

class StoreController extends Controller
{
    use HandlesStorefrontQrScan;
    use ReportsValidationFailures;
    use ScopesToBranch;

    private const LINK_SLOTS = 10;

    private const BIO_COLOUR_SLOTS = 12;

    /**
     * Storefront checkout form (submitOrder). Key order is the order the diner
     * sees the messages in.
     *
     * - `items` may be absent on a combos-only cart (PARITY-RUN T6: one
     *   "Family Meal" is a complete order).
     * - Hotel room service (H2) asks for a room, not a phone/address, so it is
     *   exempt from the phone like dine-in.
     * - The lengths mirror the `orders` columns: an over-long value used to reach
     *   MySQL and come back as a 500 carrying the raw "Data too long for column"
     *   error (naming our schema) instead of a message.
     */
    private const CHECKOUT_RULES = [
        'items' => 'required_without:combos',
        'name' => 'required|string|max:255',
        'ordering-type' => 'required|in:on-table,takeaway,delivery,room_service',
        'table' => 'required_if:ordering-type,on-table|nullable|string|max:120',
        'phone-number' => 'required_unless:ordering-type,on-table,room_service|nullable|string|max:25',
        'address' => 'required_if:ordering-type,delivery|nullable|string|max:255',
        'message' => 'nullable|string|max:255',
        'room' => 'nullable|string|max:120',
        'pay_via' => 'required',
    ];

    /** The owner's store list (rows load client-side). */
    public function index(): View
    {
        return view('owner.stores.index');
    }

    /**
     * The new-store wizard: every enabled template plus the theme set of each
     * template and of each vertical, so step 2 can filter client-side. store()
     * re-checks the pick against the chosen vertical.
     */
    public function create(Request $request): RedirectResponse|View
    {
        if ($spotlight = $this->storeLimitSpotlight($request->user())) {
            // WI-A2: the landing page reads spotlight_limit to show the upgrade path.
            return $this->refuseOverLimit($spotlight);
        }

        $templates = $this->getStoreTemplates();
        $themeSets = app(VerticalThemeSets::class);
        $registry = app(VerticalRegistry::class);

        return view('owner.stores.create', [
            'templates' => $templates,
            'templateSets' => array_map(fn ($slug) => $themeSets->setFor($slug), array_combine(array_keys($templates), array_keys($templates))),
            'verticalThemeSets' => collect(array_keys((array) config('verticals.types', [])))
                ->mapWithKeys(fn ($type) => [$type => $registry->themeSet($type)])->all(),
            // Task 3: offer to import a menu the owner already drafted in AI Studio.
            'studioDraftPending' => app(StudioDraftImporter::class)->hasPendingDraft($request),
        ]);
    }

    /**
     * Create a store from the wizard. Gates, in order: verified email (unless
     * the site allows unverified owners), the plan's store limit (#67 add-on
     * boosts included), and the multi_vertical entitlement for non-restaurant
     * verticals (F0-6; unknown types are left to the validator).
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (! config('settings.non_active_allow') && ! $user->hasVerifiedEmail()) {
            return $this->refuseForm(___('Verify your email address to post any content.'));
        }
        if ($spotlight = $this->storeLimitSpotlight($user)) {
            return $this->refuseOverLimit($spotlight);
        }

        $storeType = $request->filled('store_type') ? (string) $request->input('store_type') : 'restaurant';
        if ($storeType !== 'restaurant'
            && array_key_exists($storeType, (array) config('verticals.types', []))
            && ! Entitlements::allows($user, 'multi_vertical')) {
            return $this->refuseForm(___('Non-restaurant stores are a premium feature. Please upgrade to create them.'))
                ->with('spotlight_limit', ['type' => 'multi_vertical']);
        }

        // Edition limits (Community: one store of each store type per business) — same rule the Store model enforces.
        $edition = app(StoreLimitGuard::class)->checkNewStore($user->account_id ? (int) $user->account_id : null, $storeType);
        if ($edition->refused()) {
            return $this->refuseForm($edition->message())->with('store_limit', $edition->toArray() + ['message' => $edition->message()]);
        }

        $validator = Validator::make($request->all(), $this->newStoreRules($storeType));
        if ($validator->fails()) {
            // Owners reported "creating a store does not complete and sends me
            // back to the beginning": the wizard reopened at step 1 with no
            // visible reason. Reopen it at the step that holds the first
            // failing field and keep the error bag so the form can list what to
            // fix. Field names only are logged (never values).
            $failed = array_keys($validator->failed());

            Log::info('[store create] validation failed', [
                'user_id' => $user->id,
                'fields' => $failed,
            ]);

            return $this->refuseForm($this->validationSummary($validator))
                ->withErrors($validator)
                ->with('create_step', $this->createStepFor($failed));
        }

        $fields = self::newStoreTextFields();
        $attrs = array_merge(array_fill_keys($fields, null), $request->only($fields), [
            'user_id' => $user->id,
            'store_type' => $storeType,
            'cover_image' => image_upload($request->file('cover_image'), 'storage/store/cover/', '300'),
            'main_image' => image_upload($request->file('main_image'), 'storage/store/logo/', '500x500'),
        ]);
        $slug = trim((string) $request->input('slug'));
        $store = GlobalSlugService::writeWithUniqueSlug(
            (string) $request->input('title'),
            fn (string $s): Store => Store::create($attrs + ['slug' => $s]),
            $slug !== '' ? $slug : null,
        );

        foreach ($this->newStoreOptions($request->input('store_template')) as $name => $value) {
            StoreOption::updateStoreOption($store->id, $name, $value);
        }

        // Task 3: seed the menu from an AI Studio draft; the store stands even if that fails.
        $imported = null;
        try {
            $imported = app(StudioDraftImporter::class)->importInto($store, $user, $request);
        } catch (\Throwable $e) {
            Log::warning('[studio import] '.$e->getMessage());
        }

        app_alert_success(($imported && $imported['items'] > 0)
            ? ___('Store created and :count items imported from AI Studio.', ['count' => $imported['items']])
            : ___('Your store has been created.'));

        // Clear confirmation on the stores list: which store was created and
        // what to do next (add menu items, open the store, print the QR).
        return redirect()->route('stores.index')->with('store_created', [
            'id' => $store->id,
            'title' => $store->title,
            'items' => (int) ($imported['items'] ?? 0),
        ]);
    }

    /**
     * The create-wizard step that holds the first failing field, so a server
     * validation error reopens the wizard where the owner can fix it.
     *
     * @param  list<string>  $failed
     */
    private function createStepFor(array $failed): string
    {
        /** @var array<string, string> $stepOf */
        $stepOf = [
            'store_type' => 'basics', 'color' => 'basics', 'title' => 'basics', 'slug' => 'basics',
            'sub_title' => 'basics', 'timing' => 'basics', 'phone' => 'basics', 'address' => 'basics',
            'description' => 'basics',
            'main_image' => 'template', 'cover_image' => 'template', 'store_template' => 'template',
        ];

        foreach (['basics', 'template'] as $step) {
            foreach ($failed as $field) {
                if (($stepOf[$field] ?? 'confirm') === $step) {
                    return $step;
                }
            }
        }

        return 'confirm';
    }

    /** No screen of its own: a single store opens on its menu. */
    public function show($store): RedirectResponse
    {
        return redirect()->route('stores.menu', $store);
    }

    /**
     * Store editor. Templates are scoped to the store's own vertical, as in the
     * wizard (StoreSettingsRules validates the pick the same way). Cuisines feed
     * the directory picker (DIRECTORY L1 T1-1), which the view gates itself.
     */
    public function edit(Request $request, Store $store): View
    {
        abort_unless($this->ownsStore($request, $store), 404);

        return view('owner.stores.edit', [
            'user' => $request->user(),
            'store' => $store,
            'postOptions' => store_options($store->id),
            'templates' => $this->getStoreTemplates($store->store_type),
            'cuisines' => Cuisine::active()->ordered()->get(['id', 'name', 'slug']),
            'postCuisineIds' => $store->cuisines()->pluck('cuisines.id')->all(),
        ]);
    }

    /**
     * Save the store editor. Rules (StoreSettingsRules) and persistence
     * (StoreSettingsService) are shared with the reseller Store console
     * (F0-1); a string from the service is the error to show.
     */
    public function update(Request $request, Store $store): RedirectResponse
    {
        abort_unless($this->ownsStore($request, $store), 404);

        if (! config('settings.non_active_allow') && ! $request->user()->hasVerifiedEmail()) {
            return $this->refuseForm(___('Verify your email address to post any content.'));
        }

        $validator = Validator::make($request->all(), StoreSettingsRules::rules($store));
        if ($validator->fails()) {
            return $this->refuseForm($this->validationSummary($validator));
        }

        // J32 / P6-116: levies cleaned, a chosen rate preset sets the rate, an empty rate takes the country default
        $taxOptions = TaxCountryRules::applyDefaults(
            ['tax_levies' => TaxLevies::normalize($request->input('tax_levies', []))]
            + $request->only(['tax_rate_preset', 'restaurant_taxx_charge'])
            + ['fiscal_country' => $request->input('fiscal_country', store_options($store->id, 'fiscal_country'))]);
        unset($taxOptions['fiscal_country']);
        $request->merge($taxOptions);
        if ($error = app(StoreSettingsService::class)->applyOwnerUpdate($store, $request)) {
            return $this->refuseForm($error);
        }

        app_alert_success(___('Updated Successfully.'));

        return back();
    }

    /** AJAX delete. Always answers success; only the owner's own store is removed. */
    public function destroy(Request $request, Store $store): JsonResponse
    {
        if ($this->ownsStore($request, $store)) {
            $store->delete();
        }

        return response()->json(['success' => true, 'message' => ___('Deleted Successfully')]);
    }

    private function ownsStore(Request $request, Store $store): bool
    {
        return (int) $store->user_id === (int) $request->user()->id;
    }

    /** Flash an error and send the owner back to the form with their input. */
    private function refuseForm(string $message): RedirectResponse
    {
        app_alert_error($message);

        return back()->withInput();
    }

    /** @param  array{type: string, used: int, limit: int}  $spotlight */
    private function refuseOverLimit(array $spotlight): RedirectResponse
    {
        return $this->refuseForm(___('Limit exceeded, please upgrade your membership.'))
            ->with('spotlight_limit', $spotlight);
    }

    /**
     * The spotlight payload when the owner is at their store limit, else null.
     *
     * @return array{type: string, used: int, limit: int}|null
     */
    private function storeLimitSpotlight(User $user): ?array
    {
        $limit = effective_limit('restaurant');
        if (Entitlements::isUnlimitedSentinel($limit)) {
            return null;
        }
        $used = Store::where('user_id', $user->id)->count();

        return $used >= $limit ? ['type' => 'restaurant', 'used' => $used, 'limit' => $limit] : null;
    }

    /** @return list<string> e.g. link1 … link10 */
    private static function numbered(string $prefix, int $count): array
    {
        return array_map(fn (int $n) => $prefix.$n, range(1, $count));
    }

    /**
     * Plain columns a new store takes from the wizard (missing ones are stored as null).
     *
     * @return list<string>
     */
    private static function newStoreTextFields(): array
    {
        return array_merge(
            ['color', 'title', 'sub_title', 'description', 'timing', 'phone', 'address'],
            self::numbered('link_label', self::LINK_SLOTS),
            self::numbered('link', self::LINK_SLOTS),
            ['bio_title', 'never_show_title', 'bio_description'],
            self::numbered('bio_color_', self::BIO_COLOUR_SLOTS),
        );
    }

    /**
     * Wizard rules; the template must belong to the chosen vertical's theme set (C4).
     *
     * @return array<string, mixed>
     */
    private function newStoreRules(string $storeType): array
    {
        $upload = ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'];
        $needed = fn (?int $max = null) => array_filter(['required', 'string', $max ? "max:$max" : null]);
        $optional = fn (?int $max = null) => array_filter(['nullable', 'string', $max ? "max:$max" : null]);

        return [
            'color' => 'required',
            'cover_image' => $upload,
            'main_image' => $upload,
            'slug' => ['nullable', 'alpha_dash', new ReservedSlug, new PhishingSlug, new GloballyUnique('stores', 'slug')],
            'title' => $needed(150),
            'sub_title' => $optional(255),
            'description' => $needed(),
            'address' => $needed(255),
            'timing' => $optional(255),
            'phone' => $optional(20),
            'store_template' => ['required', Rule::in(array_keys($this->getStoreTemplates($storeType)))],
            'store_type' => ['nullable', Rule::in(array_keys((array) config('verticals.types', [])))],
        ]
            + array_fill_keys(self::numbered('link_label', self::LINK_SLOTS), $optional(255))
            + array_fill_keys(self::numbered('link', self::LINK_SLOTS), $optional(255))
            + ['bio_title' => $optional(150), 'never_show_title' => $optional(255), 'bio_description' => $optional()]
            + array_fill_keys(self::numbered('bio_color_', self::BIO_COLOUR_SLOTS), ['nullable']);
    }

    /**
     * Post options every new store starts with, plus the site currency.
     *
     * @return array<string, mixed>
     */
    private function newStoreOptions(?string $template): array
    {
        $on = [
            'allow_call_waiter', 'allow_auto_user_info', 'allow_auto_popup', 'open_close_store', 'prevent_order_on_close',
            'show_lang_switch_on_bio_page', 'show_background_img_on_bio_page', 'show_logo_img_on_bio_page',
            'show_title_on_bio_page', 'show_discription_on_bio_page', 'show_timing_on_bio_page', 'show_social_on_bio_page',
            'show_links_on_bio_page', 'show_continue_btn_on_bio_page', 'show_never_show_on_bio_page',
            'restaurant_on_table_order', 'store_submit_order_notification',
            // The storefront shows the branch picker unless the store turns it off: a multi-branch
            // store must be able to send a pickup order to the branch the customer picked.
            'allow_multi_branch_switch',
        ];
        $off = ['allow_slider_popup', 'show_open_close_store', 'show_open_bio_close_store'];

        $options = ['store_template' => $template] + array_fill_keys($on, 1) + array_fill_keys($off, 0);
        foreach (['code', 'sign', 'pos'] as $part) {
            // The PLATFORM's currency, never the working store's (the dashboard prices its screens in that one).
            $options['currency_'.$part] = platform_currency_option($part);
        }
        // P6-108b: the platform currency is a placeholder until the store names its country (StoreSettingsService).
        $options[StoreSettingsService::CURRENCY_DEFAULT_MARKER] = platform_currency();

        return $options;
    }

    // M15: same treatment, same rule — mechanical, no behaviour change.
    use BuildsQrCodes;
    use Concerns\BuildsReorderPayload;

    // H11: large method groups extracted to traits (mechanical, no behavior change).
    use GeneratesMenuWithAi;
    use HandlesExternalRedirect;
    use ImportsMenuCsv;
    use ManagesMenu;
    use ManagesOrders {
        trackOrder as private legacyTrackOrder;
    }
    use ManagesStoreTables;

    /**
     * Enabled menu templates for the picker (discovery + admin overlay in
     * StorefrontThemeService — disabled templates are hidden here).
     *
     * rev/multi-vertical Lane C (C4): pass a store type to scope the list to
     * its theme set (e.g. 'retail' only sees `shelf`, never a food theme).
     * Omitted (the create-wizard's initial GET, before step 1 is picked)
     * returns every enabled template, unfiltered — exactly as before.
     */
    private function getStoreTemplates(?string $storeType = null): array
    {
        $templates = app(StorefrontThemeService::class)->available();
        if ($storeType === null) {
            return $templates;
        }

        return app(VerticalThemeSets::class)->filterFor($templates, $storeType);
    }

    /**
    /**
     * #25: PWA manifest.json for a restaurant public menu
     */
    // #56: Unsubscribe info page
    public function unsubscribeInfo($slug)
    {
        $store = Store::where('slug', $slug)->firstOrFail();

        // The view renders only the store name — pass it under the key it reads.
        return view('store_templates.unsubscribe', ['restaurantName' => $store->title]);
    }

    /**
     * Owner-authored storefront info page (Terms / Privacy / About / Help).
     * Content is plain text kept in store_options (`sf_page_{slug}`). A 404 for an
     * unknown slug or an empty page keeps the account-footer links honest — a
     * theme only ever links here for pages the owner actually filled in.
     */
    public function infoPage(string $store, string $page): View
    {
        $store = Store::idOrSlug($store)->firstOrFail();

        $labels = storefront_info_page_labels();
        if (! array_key_exists($page, $labels)) {
            abort(404);
        }

        $body = trim((string) store_options($store->id, 'sf_page_'.$page, ''));
        if ($body === '') {
            abort(404);
        }

        // Render inside the store's theme (theme CSS + root token class) so the
        // page looks like the rest of the storefront, not a generic standalone.
        $postOptions = store_options($store->id);
        $theme = resolve_store_theme($postOptions->store_template ?? null, $store->store_type ?? null);
        $postOptions->store_template = $theme;

        return view('store_templates.info-page', [
            'store' => $store,
            'postOptions' => $postOptions,
            'theme' => $theme,
            'rootClass' => storefront_theme_root_class($theme),
            'pageTitle' => $labels[$page],
            'pageSlug' => $page,
            'pageBody' => $body,
            'infoPages' => storefront_info_pages($store->id),
            'backUrl' => storefront_localized_link(route('publicView', $store->slug)),
        ]);
    }

    // #56: Handle unsubscribe POST
    public function unsubscribe(Request $request, $slug)
    {
        $store = Store::where('slug', $slug)->firstOrFail();
        $email = filter_var($request->get('email', ''), FILTER_VALIDATE_EMAIL);
        $phone = $request->get('phone');

        if (! $email && ! $phone) {
            return back()->with('error', ___('Please provide an email or phone number.'));
        }

        $q = Customer::where('store_id', $store->id);
        if ($email) {
            $q->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $email))]);
        } elseif ($phone) {
            $q->where('phone', $phone);
        }
        $q->update(['marketing_consent' => false, 'sms_consent' => false, 'consent_at' => null]);

        // #104: also record an SMS opt-out so campaigns suppress this number.
        if ($phone) {
            $normalized = app(Sms::class)->normalizePhone($phone);
            if ($normalized !== '') {
                SmsOptout::firstOrCreate(
                    ['phone' => $normalized, 'store_id' => null],
                    ['source' => 'manual']
                );
            }
        }

        return back()->with('success', ___('You have been unsubscribed from marketing messages.'));
    }

    public function publicViewManifest($slug)
    {
        $store = Store::where('slug', $slug)->firstOrFail();
        $logo = media_url('storage/store/logo/', $store->main_image, $store->id);

        $manifest = [
            'name' => $store->title,
            'short_name' => Str::limit($store->title, 12, ''),
            // P1-20 (J28): per-address PWA — "/" on the store's own origin, "/{slug}" on the shared host.
            'id' => ($__pwaScope = store_sw_scope($store)),
            'start_url' => $__pwaScope,
            'scope' => $__pwaScope,
            'display' => 'standalone',
            'background_color' => $store->color ?: '#12121a',
            'theme_color' => $store->color ?: '#f5b54a',
            'orientation' => 'portrait',
            'icons' => [
                ['src' => $logo, 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $logo, 'sizes' => '512x512', 'type' => 'image/png'],
            ],
        ];

        return response()->json($manifest)
            ->header('Content-Type', 'application/manifest+json');
    }

    /**
     * BIOLINK-RUN A5 (SPEC §5): resolve the bio-first / bio-only interstitial for
     * a menu load. Returns a 302 to `{slug}/bio` (query preserved) or null to let
     * the menu render unchanged. Every bypass row in §5 is a `return null` here.
     */
    private function bioInterstitial(Store $store): ?RedirectResponse
    {
        $request = request();

        // Only GET menu loads are ever intercepted (POSTs never are).
        if (! $request->isMethod('get')) {
            return null;
        }

        // Dark = zero change: no entitlement / kill-switch off ⇒ behave exactly
        // as before this feature existed.
        if (! FeatureAccess::storeAllows($store->id, 'biolink')) {
            return null;
        }

        $mode = app(BioLinkService::class)->resolveMode($store->id);
        if ($mode !== 'bio_first' && $mode !== 'bio_only') {
            return null; // menu_first: nothing changes
        }

        // Never intercept (§5): table/item order flows, ANY qr scan (G2 — the scan
        // must reach publicView so it is counted AND limit-enforced), and a
        // logged-in owner/staff (web guard) previewing their own store.
        if ($request->has('table') || $request->has('item') || $request->has('qr-id')) {
            return null;
        }
        if (auth()->check()) {
            return null;
        }

        // bio_first fires only on the FIRST menu load per session; the bio render
        // sets `bio_seen_{id}`. bio_only fires every time.
        if ($mode === 'bio_first' && $request->session()->get('bio_seen_'.$store->id)) {
            return null;
        }

        return redirect()->to(route('bio.public', array_merge(['slug' => $store->slug], $request->query())));
    }

    /**
     * The storefront menu page. Order of gates: store lookup (slug, or numeric
     * id for old links; a renamed slug 301s), the owner's external redirect,
     * the bio interstitial, then an active owner on an active plan (else 404).
     * A matching `qr-id` counts one scan and redirects to the clean URL.
     *
     * @param  string  $slug
     */
    public function publicView($slug)
    {
        $store = $this->findStorefrontPost((string) $slug);

        // rev/seo R1: a renamed slug keeps its printed QR codes and indexed links.
        if (! $store && ($renamed = SlugRedirect::where('old_slug', $slug)->first()) && $renamed->store) {
            return redirect()->route('publicView', $renamed->store->slug, 301);
        }

        // rev/external-redirect A2: opt-out redirect, above the biolink interstitial.
        if (($extRedirect = $this->externalMenuRedirect($store)) !== null) {
            return $extRedirect;
        }

        // BIOLINK-RUN A5: never for a qr scan (G2), table/item flows, a signed-in
        // owner/staff, a non-GET or a repeat bio_first visit (see bioInterstitial()).
        if ($store && ($bioRedirect = $this->bioInterstitial($store)) !== null) {
            return $bioRedirect;
        }

        $plan = ($store && $store->user && (int) $store->user->status === 1) ? $store->user->plan() : null;
        abort_unless($plan && $plan->status, 404);
        // P1-23 (AUP 3.1): an unpublished pharmacy (country not enabled, licence missing/unreviewed/expired) is shown
        // only to its owner, who is still setting it up.
        abort_if(app(SalesRestrictions::class)->unpublishedReason($store) !== null && (int) auth()->id() !== (int) $store->user_id, 404);

        // P5: conditional-GET on the anonymous storefront menu. Skipped for a
        // tracked QR scan (`qr-id`) so scan analytics/billing always record, and
        // never for an authenticated/preview render. The signature folds in the
        // menu-cache version, so any menu edit changes the ETag immediately.
        $menuEtag = null;
        // An enforced nonce CSP keeps the 304 (AddCspHeaders sends no policy on a 304, so the browser keeps the stored
        // policy whose nonce matches the stored body) but never PUBLIC caching: a shared cache would hand one nonce to
        // many visitors. See menuCacheControl() (P4-17 follow-up).
        /* C4: `auth()->check()` resolves the DEFAULT guard (`web`, see
           config/auth.php) — the store owner. Diners sign in on the separate
           `customer` guard, so a signed-in diner used to pass this gate and
           receive `public, max-age=300` on a body that inlines their name,
           saved addresses and store-credit balance (20 templates do it). A
           shared cache could then serve customer A's page to customer B, and —
           with no proxy at all — the diner got a 304 of their own pre-edit page
           for 300s after adding an address. Dropping the ETag for a signed-in
           diner is the whole fix: with $menuEtag null the 200 leg sends no
           Cache-Control, and Symfony defaults that to `no-cache, private`.
           The `storefront_public_cache` Option is a superadmin kill switch. */
        if (config('settings.storefront_public_cache', true)
            && config('perf.menu_etag', true) && request()->isMethod('get')
            && ! request()->has('qr-id') && ! auth()->check() && ! auth('customer')->check()) {
            $menuEtag = '"'.StorefrontMenuCache::signature(
                (int) $store->id, (int) request()->get('branch_id'), get_lang()
            ).'"';
            $ifNoneMatch = trim((string) request()->headers->get('If-None-Match'));
            if ($ifNoneMatch !== '' && ($ifNoneMatch === $menuEtag || $ifNoneMatch === '*')) {
                return response('', 304)
                    ->setEtag(trim($menuEtag, '"'))
                    // C4: even an anonymous body carries this visitor's own
                    // csrf_token() + session flash flags; without Vary a shared
                    // cache may hand one visitor's token to another. The 304
                    // browser-cache win (P5) is unaffected.
                    ->header('Vary', 'Cookie')
                    ->header('Cache-Control', self::menuCacheControl());
            }
        }

        if (request()->has('qr-id') && $slug == app_xor_decrypt(request()->get('qr-id'), 'qr-scan')
            && ($scanRedirect = $this->countQrScan($store)) !== null) {
            return $scanRedirect;
        }

        $table = $this->scannedTable($store);
        // One "verified room" flag for every theme: the visitor's stashed room token verifies for the scanned room
        // (charge-to-room affordance, the current stay's requests). The printed room token never expires and every
        // past guest of the room holds it, so the guest's name, bill lines and folio link need more: the visitor
        // also holds the CURRENT stay's token (stashed when the guest opens their folio link). J52b review, J57, SF2.
        $roomVerified = $this->roomTokenVerified($store, $table);
        $guestStayVerified = $roomVerified && $this->currentStayTokenHeld($store, $table);

        $postOptions = store_options($store->id);
        /* F7-11: hide plan-gated toggles the owner is not entitled to
           BEFORE any theme renders from them, so an unentitled store
           never offers a control whose action would then be refused.
           One place instead of ~20 theme blades — see the class
           docblock for exactly which read paths are masked and why the
           owner's own settings form deliberately is not. */
        $postOptions = StorefrontToggleMask::apply($postOptions, (int) $store->id);
        /* P2-9: marketplace (Liquid) themes store `mp:<slug>@<version>` —
           capture the raw value BEFORE resolve_store_theme(), which
           rewrites unknown slugs to the fallback Blade theme. */
        $rawTemplate = $postOptions->store_template ?? null;
        /* Missing/deleted templates render the admin-configured fallback.
           Templates read the slug back off $postOptions for asset paths,
           so keep the two in sync. */
        $theme = resolve_store_theme($postOptions->store_template ?? null, $store->store_type ?? null);
        $postOptions->store_template = $theme;

        /* Admin-only live preview: ?preview_template=<slug> renders the
           storefront under a chosen template without saving anything —
           render-only, so a stuck/unfinished/disabled template can be
           eyeballed on a real store before it is switched on. Gated to
           authenticated admins and to slugs the service recognises, so
           regular visitors can never probe templates the owner has not
           published. */
        if (request()->filled('preview_template')
            && auth()->check()
            && auth()->user()->isAdmin()
            && app(StorefrontThemeService::class)->exists((string) request('preview_template'))) {
            $theme = (string) request('preview_template');
            $postOptions->store_template = $theme;
        }

        /* Menu-content language (item/category/extra/variant names +
           descriptions) is resolved from the Quick_user_lang_code cookie
           inside the models. The 17 modern templates switch language via
           the URL locale PREFIX, not that cookie, so a stale/absent
           cookie rendered e.g. Arabic item names under an English chrome
           (and vice-versa) — an intermittent EN/AR mix depending on
           whether the cookie happened to match the URL. In prefix mode,
           pin the cookie to the already-resolved app locale for these
           templates so content and chrome always agree. Legacy templates
           (cookie + reload switchers) are deliberately left untouched. */
        StorefrontThemeService::pinMenuContentLanguage($theme);

        [$menu_languages, $default_menu_language] = $this->storefrontLanguage($store, $postOptions);

        // P3-05: a kit theme (table, brew, aisle, basket, book) renders from the Storefront View Model alone — none of
        // the legacy menu / flag preparation below is read by it.
        if (KitThemes::isKit($theme) && MarketplaceThemeResolver::resolve($rawTemplate) === null) {
            return $this->kitStorefront($store, $theme, $menuEtag);
        }

        $flags = $this->orderingFlags($store, $postOptions, $table);

        $allergies = config('settings.admin_allergies')
            ? Allergy::availableFor($store->id)->orderBy('position')->get()
            : collect();

        $menus = $this->storefrontMenus($store);

        // #76: per-branch availability + price overrides (new branch_id system).
        $branches = Branch::where('store_id', $store->id)
            ->where('active', true)->orderBy('position')->get();
        $activeBranch = null;
        $branchIdParam = (int) request()->get('branch_id');
        if ($branchIdParam) {
            $activeBranch = $branches->firstWhere('id', $branchIdParam);
        }
        if ($activeBranch) {
            $branchSettings = BranchMenu::where('branch_id', $activeBranch->id)
                ->whereIn('menu_id', $menus->pluck('id'))->get()->keyBy('menu_id');
            $menus = $menus->filter(function ($menu) use ($branchSettings) {
                $s = $branchSettings->get($menu->id);

                return ! $s || $s->available;
            })->map(function ($menu) use ($branchSettings) {
                $s = $branchSettings->get($menu->id);
                if ($s && $s->price !== null) {
                    $menu->price = $s->price; // branch price override (in-memory)
                }

                return $menu;
            })->values();
        }

        /* PARITY-RUN T5: scheduled availability + happy-hour pricing.
           Runs AFTER the branch price override above, so a branch
           price is the base a schedule discounts from (a percentage
           off must come off the price this branch actually charges).

           decorate() rewrites the in-memory `price` attribute every
           theme already prints, which is why all ~40 storefront
           templates show the scheduled price without a markup
           change; `original_price` / `schedule_label` /
           `available_now` are additive extras themes may read.
           Nothing is persisted, and the ORDER path re-resolves the
           price server-side rather than trusting anything rendered
           here — see MenuScheduleService::snapshotFor(). */
        MenuScheduleService::decorate($menus, (int) $store->id);

        /* Items outside their window (or their category's window)
           are dropped from the menu, unless the owner prefers to
           grey them out with an "available from HH:MM" note. Sold-out
           is a separate, pre-existing state and is untouched. */
        if (empty($postOptions->menu_show_unavailable)) {
            $menus = $menus->filter(fn ($m) => $m->getAttribute('available_now') !== false)->values();
        }

        $total_menus = $menus->keyBy('id')->all();

        remember_platform_currency(); // bug batch 10 · 1: the swap below is display only
        config([
            'settings.currency_sign' => $postOptions->currency_sign ?? null,
            // store_currency_pos() falls back to the platform default; the raw option
            // was null for stores that never chose and forced a suffix ("24.00$").
            'settings.currency_pos' => store_currency_pos($store->id),
            // USD when the store never set a code (an empty code blanked the switcher,
            // the JS base currency and the "charged in ___." notice).
            'settings.currency_code' => (string) (($postOptions->currency_code ?? null) ?: 'USD'),
            'settings.currency_rtl_auto' => store_options($store->id, 'currency_rtl_auto', '1'),
        ]);

        // Social proof (#32): scans today + most-ordered items. "Today" is
        // the store's own day (date is an app-tz wall-clock, so the store
        // midnight is normalised back to the app tz). Platform-tz store → same.
        $scans_today = StoreView::where('store_id', $store->id)
            ->where('date', '>=', Carbon::today(store_tz($store->id))->setTimezone(config('app.timezone') ?: 'UTC'))
            ->count();

        // Storefront promo / hero banners (active, ordered).
        $menuBanners = MenuBanner::where('store_id', $store->id)
            ->where('active', true)->orderBy('position')->orderBy('id')->get();

        /* P2-9: marketplace (Liquid) theme branch — additive, and a
           render failure falls through to the Blade fallback below.
           A diner must NEVER see a blank menu.
           P4-1: a paid marketplace theme renders only while the
           owner's account holds a license — otherwise Blade fallback. */
        /* Theme Studio: a theme chosen in the studio but not published
           yet is shown ONLY to its owner's preview (?preview_theme=1);
           every other visitor keeps the live theme. */
        $studioPending = (string) ($postOptions->studio_pending_template ?? '');
        if ($studioPending !== '' && request()->boolean('preview_theme')
            && auth()->check() && (int) auth()->id() === (int) $store->user_id) {
            $rawTemplate = $studioPending;
        }

        if (($mpTheme = MarketplaceThemeResolver::resolve($rawTemplate))
            && ThemePurchaseService::entitledForSlug((int) $store->user_id, $mpTheme->slug)) {
            $renderStart = microtime(true); // P5-3 latency metric
            try {
                // The store's published customizer values + Studio layout
                // (VERSIONLESS key, so a theme update never wipes them).
                $themeRow = ThemeSetting::where('store_id', $store->id)
                    ->where('template', 'mp:'.$mpTheme->slug)
                    ->first();
                $storeLayout = is_array($themeRow?->layout) ? $themeRow->layout : null;
                $storeSettings = is_array($themeRow?->settings) ? $themeRow->settings : [];

                /* Theme Studio B0.4: owner draft preview — the SAME gate as
                   the Blade themes' theme_setting() (?preview_theme=1 + the
                   signed-in owner). The storefront page cache never stores
                   these requests (preview param + logged-in visitor). */
                $ownerPreview = request()->boolean('preview_theme')
                    && auth()->check()
                    && (int) auth()->id() === (int) $store->user_id;
                /* Theme Studio C1: the editor's preview iframe (?studio=1) —
                   the SAME owner gate, never for anyone else, so a public
                   render is byte-free of data-studio-* and the bridge. */
                $studioMode = $ownerPreview && request()->boolean('studio');
                if ($ownerPreview && $themeRow) {
                    if (is_array($themeRow->draft)) {
                        $storeSettings = array_merge($storeSettings, $themeRow->draft);
                    }
                    if (is_array($themeRow->layout_draft)) {
                        $storeLayout = $themeRow->layout_draft;
                    }
                }

                $renderVars = [
                    'store' => $store,
                    'postOptions' => $postOptions,
                    'total_menus' => $total_menus,
                    'allergies' => $allergies,
                    'menuBanners' => $menuBanners,
                    'branches' => $branches,
                    'activeBranch' => $activeBranch,
                    'table' => $table,
                    'roomVerified' => $roomVerified,
                    'guestStayVerified' => $guestStayVerified,
                    'customer' => auth('customer')->user(),
                    'menu_languages' => $menu_languages,
                    'default_menu_language' => $default_menu_language ?: null,
                    'scans_today' => $scans_today,
                    // Theme Studio B0.3: the `settings` global.
                    'settings' => ThemeSettingValues::resolve($mpTheme, $storeSettings, $storeLayout),
                    'flags' => [
                        'allow_order' => (bool) $flags['allow_order'],
                        'on_table' => (bool) $flags['allow_on_table'],
                        'takeaway' => (bool) $flags['allow_takeaway'],
                        'delivery' => (bool) $flags['allow_delivery'],
                        'room_service' => (bool) $flags['allow_room_service'],
                        'payment' => (bool) $flags['allow_payment'],
                        'scheduling' => ! empty($postOptions->allow_order_scheduling),
                    ],
                ];

                // Theme Studio B0.6: hours, stories, social, announcement,
                // feature switches — the data the Blade themes compute
                // in their own @php blocks and layout partials.
                $renderVars['extras'] = StorefrontExtras::for($store, $postOptions, $renderVars['flags']);

                // A store that never saved a currency sign: the Liquid `money`
                // filter (server) and the commerce runtime (client) must print
                // the SAME sign, or cards read "22.40" next to a "$22.40" cart.
                if (trim((string) config('settings.currency_sign')) === '') {
                    config(['settings.currency_sign' => CommerceRuntime::fallbackSign((string) config('settings.currency_code', 'USD'))]);
                }

                $themeContext = app(ThemeContext::class);
                $commerce = app(CommerceRuntime::class);
                $globals = $themeContext->build($renderVars);
                $registers = $themeContext->registers($renderVars)
                    + $commerce->registers($renderVars, $mpTheme) // B0.10: per-theme runtime (v1 default)
                    + [
                        'theme_asset_map' => $mpTheme->assetMap(),
                        // B4: theme-bundled translations for the active language.
                        'theme_locale_map' => $mpTheme->locales($default_menu_language->code ?? app()->getLocale()),
                        // Theme Studio C1: studio_text / studio_block / section attrs.
                        'studio_mode' => $studioMode,
                    ];

                /* Theme Studio C1: one section of the draft for the editor's
                   fragment endpoint (ThemeStudioController@section). */
                if ($studioMode && $mpTheme->isSectioned() && request()->filled('studio_section')) {
                    $fragmentStatus = 200;
                    try {
                        $fragment = app(SectionedTemplate::class)->renderOne(
                            $mpTheme,
                            (string) request()->query('studio_section'),
                            $globals,
                            $registers,
                            ThemeCustomizerService::sectionValues($storeSettings),
                            $storeLayout,
                        );
                        $fragmentStatus = $fragment === null ? 404 : 200;
                    } catch (RenderFailedException $e) {
                        report($e);
                        PlatformMetrics::record('studio.render.fail', $mpTheme->slug);
                        $fragment = null;
                        $fragmentStatus = 422;
                    }

                    return response($fragment ?? '', $fragmentStatus)
                        ->header('Content-Type', 'text/html; charset=UTF-8')
                        ->header('Cache-Control', 'no-store, private')
                        ->header('X-Studio-Fragment', '1');
                }

                if ($mpTheme->isSectioned()) {
                    /* P2-3: OS 2.0 sectioned template. Merchant
                       settings are keyed by section id under a
                       VERSIONLESS template key, so a theme update
                       never wipes them. */
                    $merchantSections = ThemeCustomizerService::sectionValues($storeSettings);
                    // Theme Studio B0.2: the store's own published layout
                    // (sections, order, blocks) replaces index.json.
                    $body = app(SectionedTemplate::class)->render(
                        $mpTheme, $globals, $registers, $merchantSections, 'live', $storeLayout,
                    );
                } else {
                    $body = app(LiquidRenderer::class)->render(
                        $mpTheme->templateSource(), $globals, 'live', $registers, $mpTheme->snippets(),
                    );
                }

                PlatformMetrics::record('render.ok', $mpTheme->slug, (int) ((microtime(true) - $renderStart) * 1000));

                $shell = response()->view('store_templates.marketplace-shell', [
                    'store' => $store,
                    'body' => $body,
                    'lang' => $default_menu_language->code ?? app()->getLocale(),
                    'dir' => ($default_menu_language->direction ?? null) === 'rtl' ? 'rtl' : 'ltr',
                    'shellStyles' => ShellStyles::for($mpTheme, $storeSettings, $storeLayout),
                    'studio' => $studioMode,
                ]);

                // A draft must never be stored by a browser/proxy cache either.
                return $ownerPreview ? $shell->header('Cache-Control', 'no-store, private') : $shell;
            } catch (\Throwable $e) {
                // ANY marketplace-path failure (render breach, bad
                // data, missing file) → report + Blade fallback.
                // A diner must never see a blank menu or a 500.
                report($e);
                PlatformMetrics::record('render.fail', $mpTheme->slug);
            }
        }

        // P4-16: crave ships its first cards only on a very large catalogue; script.js asks for the others by id
        if ($theme === 'crave' && request()->query('fragment') === 'crave') {
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) request()->query('ids', ''))))));
            abort_if($ids === [] || count($ids) > 120, 422);

            return response()
                ->view('store_templates.crave.items-fragment', [
                    'store' => $store, 'postOptions' => $postOptions, 'allergies' => $allergies, 'total_menus' => $total_menus,
                    'craveOnlyIds' => $ids,
                ])
                ->header('Content-Type', 'application/json')
                ->header('Cache-Control', 'private, no-store')
                ->header('X-Robots-Tag', 'noindex');
        }

        $storefront = view('store_templates.'.$theme.'.index', $flags + [
            'store' => $store,
            'theme' => $theme,
            'postOptions' => $postOptions,
            'plan' => $plan,
            'menu_languages' => $menu_languages,
            'default_menu_language' => $default_menu_language,
            'allergies' => $allergies,
            'total_menus' => $total_menus,
            'table' => $table,
            'roomVerified' => $roomVerified,
            'guestStayVerified' => $guestStayVerified,
            'scans_today' => $scans_today,
            'menuBanners' => $menuBanners,
            'branches' => $branches,
            'activeBranch' => $activeBranch,
        ]);

        // P5: attach the conditional-GET ETag so the next unchanged load 304s.
        if (! empty($menuEtag)) {
            return response($storefront)
                ->setEtag(trim($menuEtag, '"'))
                ->header('Vary', 'Cookie')   // C4 — see the 304 leg above
                ->header('Cache-Control', self::menuCacheControl());
        }

        return $storefront;
    }

    /**
     * Order tracking. The page itself is the Ordering module's (language, order, plan); when the store's theme is a kit
     * theme the same order is shown through the theme's own track view, built from the view model (P3-05).
     */
    public function trackOrder(Request $request, string $token): mixed
    {
        $response = $this->legacyTrackOrder($request, $token);

        if ($response instanceof \Illuminate\View\View && str_starts_with($response->name(), 'store_templates.') && str_ends_with($response->name(), '.track')) {
            $data = $response->getData();
            $theme = (string) ($data['theme'] ?? '');
            if (KitThemes::isKit($theme) && ($data['order'] ?? null) instanceof Order && ($data['store'] ?? null) instanceof Store) {
                return view('store_templates.'.$theme.'.track', app(KitStorefront::class)->track($data['store'], $data['order'], $theme));
            }
        }

        return $response;
    }

    /**
     * P3-05: the store page of a kit theme (view model, theme views). `?fragment=item&item=<public id>` answers only
     * that item's sheet (the markup the theme JS opens in place). The conditional-GET ETag is kept only for the
     * plain page: the table, item and branch in the URL change the body.
     */
    private function kitStorefront(Store $store, string $theme, ?string $menuEtag): mixed
    {
        $kit = app(KitStorefront::class);

        // P3-11: the `book` page of the booking engine (and of a laundry) carries the last step's answer and the signed-in customer: never shared, never revalidated on the menu's etag
        if ($theme === 'book' && request()->query('fragment') !== 'item' && ($kit->bookingMode($store, $theme) || $kit->laundryMode($store, $theme))) {
            return response(view('store_templates.book.index', $kit->home($store, request(), $theme)))->header('Cache-Control', 'private, no-store');
        }

        if (request()->query('fragment') === 'item') {
            $item = request()->query('item');
            $data = is_string($item) && preg_match('/^[0-9a-f-]{36}$/i', $item) === 1 ? $kit->item($store, request(), $theme, strtolower($item)) : null;
            abort_if($data === null, 404);

            // a theme may draw its own sheet (brew: segmented / chip choices, "Name on the cup")
            $sheet = view()->exists('store_templates.'.$theme.'.components.item-sheet') ? 'store_templates.'.$theme.'.components.item-sheet' : 'store_templates._kit.components.item-sheet';

            return response()
                ->view($sheet, $data)
                ->header('Cache-Control', 'private, no-store')
                ->header('X-Robots-Tag', 'noindex');
        }

        // P3-08 (aisle "Load more"): only the next page of product cards, for the theme script to append
        if (request()->query('fragment') === 'grid' && view()->exists('store_templates.'.$theme.'.components.grid-page')) {
            return response()
                ->view('store_templates.'.$theme.'.components.grid-page', $kit->home($store, request(), $theme))
                ->header('Cache-Control', 'private, no-store')
                ->header('X-Robots-Tag', 'noindex');
        }

        // P4-16 (very large food catalogues): only the next cards of one category, for kit.js to append in place
        if (request()->query('fragment') === 'cat' && in_array($theme, FoodCatalogPaging::THEMES, true)) {
            $category = request()->query('category');
            abort_unless(is_string($category) && $category !== '', 404);
            $view = view()->exists('store_templates.'.$theme.'.components.cat-page') ? 'store_templates.'.$theme.'.components.cat-page' : 'store_templates._kit.components.cat-page';

            return response()
                ->view($view, $kit->home($store, request(), $theme) + [
                    'category' => $category,
                    'offset' => max(0, (int) request()->query('offset', 0)),
                    'limit' => FoodCatalogPaging::pageSize(),
                ])
                ->header('Cache-Control', 'private, no-store')
                ->header('X-Robots-Tag', 'noindex');
        }

        $data = $kit->home($store, request(), $theme);
        // P3-08 (aisle): a product is a page of its own (a theme that draws one has an `item` view)
        $page = ! empty($data['item']) && view()->exists('store_templates.'.$theme.'.item') ? 'item' : 'index';
        $storefront = view('store_templates.'.$theme.'.'.$page, $data);

        // a signed-in customer's page carries their own lines (the `reorder` rail), a kiosk session's page the kiosk layout:
        // never shared, never revalidated on the menu's etag
        if (auth('customer')->check() || request()->attributes->get('kiosk.themed') === true) {
            return response($storefront)->header('Cache-Control', 'private, no-store');
        }

        if (! empty($menuEtag) && ! request()->hasAny(['table', 'item', 'branch', 'branch_id', 'rt', 'preview_theme', 'preview_template', 'lang', 'q', 'filter', 'sort', 'cursor', 'category', 'fragment', 'offset', 'in_stock', 'price_min', 'price_max', 'variant'])) {
            return response($storefront)
                ->setEtag(trim($menuEtag, '"'))
                ->header('Vary', 'Cookie')
                ->header('Cache-Control', self::menuCacheControl());
        }

        return $storefront;
    }

    /**
     * The store a checkout posts to. Route-model binding normally hands it
     * over; when it did not (an unbound or replaced route parameter) the raw
     * parameter is matched against the id and then the slug.
     */
    private function checkoutStore(Request $request, Store $bound): Store
    {
        if ($bound->exists) {
            return $bound;
        }

        $param = $request->route('store');
        if ($param instanceof Store) {
            return $param;
        }

        return Store::query()->idOrSlug($param)->firstOrFail();
    }

    /** A store by slug; a numeric path is tried as an id first (old links). */
    private function findStorefrontPost(string $slug): ?Store
    {
        $byId = is_numeric($slug) ? Store::find($slug) : null;

        return $byId ?? Store::firstWhere('slug', $slug);
    }

    /**
    /**
     * The store's menu languages that the platform still serves, and the one
     * this page renders in: the URL locale in prefix mode, else the visitor's
     * `Quick_user_lang_code` cookie, else the store default. The owner's
     * customizer preview may switch to any of them (WI-U4). The chosen code
     * becomes the app locale, and the session locale for anonymous visitors
     * only (WI-G1: a signed-in owner previewing must keep their panel language).
     * Without languages (or when none resolves) both are [].
     *
     * @return array{0: Collection<int, Language>|array{}, 1: Language|array{}}
     */
    private function storefrontLanguage(Store $store, object $postOptions): array
    {
        $codes = $postOptions->menu_languages ?? null;
        $storeDefault = $postOptions->default_language ?? null;
        if (empty($codes) || empty($storeDefault)) {
            return [[], []];
        }

        $languages = Language::query()->whereIn('code', (array) $codes)->where('active', 1)->get();

        // Prefix mode: the URL's language prefix decides. An UNPREFIXED store URL carries no choice (get_lang() is then only
        // the install's root language), so the store's own default language applies there.
        $prefixed = in_array(strtolower((string) request()->segment(1)), array_map('strtolower', array_keys(get_active_languages())), true);
        $requested = config('settings.include_language_code') ? ($prefixed ? get_lang() : $storeDefault) : ($_COOKIE['Quick_user_lang_code'] ?? null);
        // P3-06: the kit themes' language buttons link to `?lang=<code>` (ViewModelBuilder::locales): a code the store offers
        // switches the page and is remembered like the legacy themes' own switch (the same plain cookie).
        $asked = request()->query('lang');
        if (! config('settings.include_language_code') && is_string($asked) && $languages->firstWhere('code', $asked) !== null) {
            $requested = $asked;
            $_COOKIE['Quick_user_lang_code'] = $asked;
            cookie()->queue(cookie('Quick_user_lang_code', $asked, 60 * 24 * 365, '/', null, null, false, false, 'lax'));
        }
        $current = (config('settings.include_language_code') || ! empty($requested))
            ? $languages->firstWhere('code', $requested)
            : null;
        $current = $current ?: $languages->firstWhere('code', $storeDefault);
        /* The saved default can be a language the platform has since
           deactivated (it is filtered out by ->where('active', 1) above) —
           fall back to the store's first live menu language. */
        $current = $current ?: $languages->first();

        if (request()->boolean('preview_theme')
            && request()->filled('preview_lang')
            && auth()->check()
            && (int) auth()->id() === (int) $store->user_id) {
            $current = $languages->firstWhere('code', (string) request()->query('preview_lang')) ?: $current;
        }

        if (! $current) {
            return [$languages, []];
        }

        App::setLocale($current->code);
        if (! auth()->check()) {
            Session::put('locale', $current->code);
        }

        return [$languages, $current];
    }

    /**
     * The allow_* flags every template reads. Ordering needs the plan's
     * allow_ordering entitlement (P6-2); online payment also needs the admin
     * switch. Room service needs a scanned room (area_type 'room') at a store
     * with hotel mode on — the only way a template can offer it. The legacy
     * plan flags (allow_advanced … allow_onlybio) stay 0 for old templates.
     *
     * @return array<string, mixed>
     */
    private function orderingFlags(Store $store, object $postOptions, ?StoreTable $table): array
    {
        $flags = array_fill_keys([
            'allow_order', 'allow_on_table', 'allow_takeaway', 'allow_delivery', 'allow_payment', 'allow_room_service',
            'allow_advanced', 'allow_pro', 'allow_on_free', 'allow_on_try', 'allow_on_main', 'allow_viewonly', 'allow_custom', 'allow_onlybio',
        ], 0);
        if (! Entitlements::has($store->user, 'allow_ordering')) {
            return $flags;
        }

        $flags['allow_on_table'] = $postOptions->restaurant_on_table_order ?? null;
        $flags['allow_takeaway'] = $postOptions->restaurant_takeaway_order ?? null;
        // Pricing follow-ups: delivery only when the store's plan includes it (a plan without the key keeps it).
        $flags['allow_delivery'] = PlanGate::storeAllows($store, PlanGate::DELIVERY) ? ($postOptions->restaurant_delivery_order ?? null) : 0;
        $flags['allow_payment'] = config('settings.admin_allow_online_payment') ? ($postOptions->restaurant_online_payment ?? null) : 0;
        $flags['allow_room_service'] = ($table && $table->area_type === 'room' && app(RoomCharge::class)->enabled($store->id)) ? 1 : 0;
        $flags['allow_order'] = $flags['allow_on_table'] || $flags['allow_takeaway'] || $flags['allow_delivery'] || $flags['allow_room_service'];

        return $flags;
    }

    /**
     * Active storefront items with their active variants, option groups and
     * extras, by position. P5: cached per store/branch/locale; time-sensitive
     * branch prices and schedules are applied by the caller afterwards.
     *
     * @return Collection<int, Menu>
     */
    private function storefrontMenus(Store $store): Collection
    {
        /* P6-49: with automatic eager loading on (every non-local environment), each model of the cached 2,000-item
           collection wraps itself in a one-model collection while it is unserialized, which was over a third of the read.
           That wrapper only ever loaded a missing relation for that one model (the same single query as a plain lazy
           load), so reading the cache with it off changes no query: it just does not pay for 2,000 wrappers per page. */
        $autoload = Model::isAutomaticallyEagerLoadingRelationships();
        Model::automaticallyEagerLoadRelationships(false);
        try {
            return $this->cachedStorefrontMenus($store);
        } finally {
            Model::automaticallyEagerLoadRelationships($autoload);
        }
    }

    /** @return Collection<int, Menu> */
    private function cachedStorefrontMenus(Store $store): Collection
    {
        return StorefrontMenuCache::remember(
            (int) $store->id,
            (int) request()->get('branch_id'),
            get_lang(),
            function () use ($store) {
                $onlyActive = fn ($q) => $q->where('active', 1);
                $menus = Menu::with([
                    'category',
                    'brand', // the shelf theme prints the brand of every item
                    // Liquid theme surface (item.reviews): approved-only via the
                    // relation itself — no closure needed here.
                    'reviews',
                    'variantOptions' => $onlyActive,
                    'variants' => $onlyActive,
                    'extras' => $onlyActive,
                    // #global-modifiers: extras attached from the reusable library (active only)
                    'attachedExtras' => function ($q) {
                        $q->where('modifiers.active', true)->wherePivot('active', true);
                    },
                    // #global-modifiers: global variant-option GROUPS attached from the library
                    'attachedVariantOptions' => function ($q) {
                        $q->where('modifier_groups.is_global', true)->wherePivot('active', true);
                    },
                ])
                    ->visibleOnStorefront()
                    ->where(['store_id' => $store->id, 'active' => '1'])
                    ->orderBy('position')
                    ->get();

                // Surface global (library) extras to the customer by merging them into the
                // `extras` relation every storefront theme already reads — per-item extras
                // first, then attached library extras (with any per-attach price override),
                // de-duplicated by id. No theme markup changes required.
                foreach ($menus as $menu) {
                    $own = $menu->getRelation('extras');
                    $attached = $menu->getRelation('attachedExtras')->map(function ($e) {
                        if ($e->pivot && $e->pivot->price_override !== null) {
                            $e->price = $e->pivot->price_override;
                        }

                        return $e;
                    });
                    $ownIds = $own->pluck('id')->all();
                    $merged = $own->concat($attached->reject(fn ($e) => in_array($e->id, $ownIds)))->values();
                    $menu->setRelation('extras', $merged);

                    // Expose attached global option groups (with per-attach rule overrides applied).
                    $groups = $menu->getRelation('attachedVariantOptions')->map(function ($g) {
                        if ($g->pivot) {
                            if ($g->pivot->required !== null) {
                                $g->required = (bool) $g->pivot->required;
                            }
                            if ($g->pivot->min_select !== null) {
                                $g->min_select = (int) $g->pivot->min_select;
                            }
                            if ($g->pivot->max_select !== null) {
                                $g->max_select = (int) $g->pivot->max_select;
                            }
                        }

                        return $g;
                    });
                    $menu->setRelation('globalGroups', $groups);
                }

                return $menus;
            }
        );
    }

    /**
     * A diner at a table asks for the waiter or for the bill; staff screens
     * list the WaiterCall rows.
     */
    public function callTheWaiter(Request $request, Store $store): JsonResponse
    {
        $check = validator($request->all(), [
            'table' => 'required|integer',
            'type' => 'nullable|in:call_waiter,request_bill',
        ]);
        if ($check->fails()) {
            return $this->validationFailureJson($check);
        }

        WaiterCall::create([
            'store_id' => $store->getKey(),
            'table_no' => $request->get('table'),
            'type' => $request->get('type', 'call_waiter'),
        ]);

        return response()->json(['success' => true, 'message' => ___('Saved Successfully')]);
    }

    /**
     * T1: JSON bookable slots for the checkout schedule picker.
     * GET {store}/schedule-slots?type=takeaway&date=YYYY-MM-DD&branch_id=..
     * Returns { enabled, dates: [Y-m-d,..], slots: [{value,label,full}], tz }.
     */
    public function scheduleSlots(Request $request, Store $store)
    {
        if (! $store->exists) {
            $store = Store::query()
                ->where('id', $request->route('store'))
                ->orWhere('slug', $request->route('store'))
                ->firstOrFail();
        }

        $scheduling = app(SchedulingService::class);
        $type = (string) $request->get('type', 'takeaway');
        if (! in_array($type, SchedulingService::TYPES, true)) {
            $type = 'takeaway';
        }

        if (! $scheduling->enabled($store->id, $type)) {
            return response()->json(['enabled' => false, 'dates' => [], 'slots' => []]);
        }

        $branch = null;
        if ($branchId = (int) $request->get('branch_id')) {
            $branch = Branch::where('id', $branchId)->where('store_id', $store->id)->first();
        }

        $tz = $scheduling->timezone($store->id, $branch);
        $today = Carbon::now($tz);
        $maxDays = $scheduling->maxDays($store->id);

        // Selectable dates = today..today+maxDays that have at least one open slot.
        $dates = [];
        for ($i = 0; $i <= $maxDays; $i++) {
            $d = $today->copy()->addDays($i)->format('Y-m-d');
            if (! empty($scheduling->slotsFor($store->id, $branch, $type, $d))) {
                $dates[] = $d;
            }
        }

        $date = (string) $request->get('date', $dates[0] ?? $today->format('Y-m-d'));
        $slots = array_map(function ($s) {
            return [
                'value' => $s['at']->format('Y-m-d H:i'),
                'label' => $s['label'],
                'full' => (bool) $s['full'],
            ];
        }, $scheduling->slotsFor($store->id, $branch, $type, $date));

        return response()->json([
            'enabled' => true,
            'tz' => $tz,
            'date' => $date,
            'dates' => $dates,
            'slots' => $slots,
        ]);
    }

    /**
     * FIX-2 F2-3: may this store run curbside pickup right now?
     *
     * Platform kill-switch veto ALWAYS, plus the store OWNER's entitlement once
     * `curbside` is plan_gated (mirrors CrossCartService::storeEntitled — while
     * the key is ungated this is kill-only, i.e. today's behavior byte for byte).
     * Store-scoped because every caller is an anonymous diner.
     */
    public static function curbsideAllowed(int $storeId): bool
    {
        return FeatureAccess::storeAllows($storeId, 'curbside');
    }

    /**
     * T5 curbside "I'm here": the customer signals arrival from the order-status page;
     * staff get a push with the vehicle details. Token-scoped, idempotent.
     */
    public function curbsideArrived(Request $request, string $token)
    {
        // FIX-2 F2-3: kill veto + store-owner entitlement, store-scoped (the caller
        // is an anonymous diner). Replaces the `feature:curbside` route middleware,
        // which resolved the wrong subject once the key became plan_gated.
        // Kill is checked BEFORE the order lookup so a killed platform 403s rather
        // than leaking token validity through a 404 (and so FeatureGateAuditTest's
        // bogus-token kill assertion keeps its meaning).
        abort_unless(FeatureRegistry::isEnabled('curbside'), 403, ___('This feature is currently unavailable.'));
        $order = Order::where('track_token', $token)->firstOrFail();
        abort_unless(self::curbsideAllowed((int) $order->store_id), 403, ___('This feature is not included in your current plan.'));
        if (! $order->is_curbside) {
            return response()->json(['success' => false, 'message' => ___('This is not a curbside order.')]);
        }
        if (! $order->curbside_arrived_at) {
            $order->curbside_arrived_at = now();
            $order->save();
            try {
                app(MessageQueue::class)->staffPush(
                    (int) $order->store_id,
                    ___('Customer arrived').' #'.$order->id,
                    trim((string) ($order->vehicle_details ?: $order->customer_name)),
                    '/staff-app'
                );
            } catch (\Throwable $e) {
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * P1: subscribe THIS browser to Web Push for one order (the closed-tab
     * "order ready"). Anonymous + token-scoped — no customer account. The order
     * must exist and be non-terminal. Upserts on the endpoint hash so a device
     * re-subscribing (or moving to a new order) never duplicates rows.
     */
    public function pushSubscribe(Request $request, string $token): JsonResponse
    {
        $order = Order::where('track_token', $token)->firstOrFail();

        // A finished order never accepts new subscriptions.
        if (in_array((string) $order->status, CustomerPushService::TERMINAL, true)) {
            return response()->json(['ok' => false, 'message' => ___('This order is already complete.')], 422);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1000', 'url'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        CustomerPushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'order_id' => $order->id,
                'store_id' => $order->store_id,
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'content_encoding' => 'aesgcm',
                'failed_at' => null,
            ]
        );

        return response()->json(['ok' => true]);
    }

    /** P1: unsubscribe this browser from an order's Web Push (token-scoped). */
    public function pushUnsubscribe(Request $request, string $token): JsonResponse
    {
        $order = Order::where('track_token', $token)->firstOrFail();

        $endpoint = (string) $request->input('endpoint', '');
        if ($endpoint !== '') {
            CustomerPushSubscription::where('order_id', $order->id)
                ->where('endpoint_hash', hash('sha256', $endpoint))
                ->delete();
        }

        return response()->json(['ok' => true]);
    }

    /**
     * J27-A2: the answer to a resent checkout (same token, same cart) — the order it already placed. The WhatsApp
     * hand-off is not repeated (the first answer opened it; a second would send the guest's message twice).
     */
    private function clientOrderReplayJson(Order $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => '',
            'whatsapp_url' => '',
            'track_url' => $order->track_token ? route('store.orderTrack', $order->track_token) : null,
            'duplicate' => true,
        ]);
    }

    /** J27-A2: the same token was already used for a different cart — no order, 409; the client starts over. */
    private function clientOrderConflictJson(ClientOrderConflict $e): JsonResponse
    {
        return response()->json(['success' => false, 'conflict' => true, 'message' => $e->getMessage()], 409);
    }

    /**
     * Storefront checkout: validate the cart and the guest, create the order
     * (legacy write path or the H15 unified pipeline), then notify the owner,
     * hand off to WhatsApp and/or the payment page, and answer the theme.
     */
    public function submitOrder(Request $request, Store $store)
    {
        $store = $this->checkoutStore($request, $store);

        // P3-03: the storefront JS cart (public-id lines) becomes the legacy fields below; other requests pass untouched.
        if (($adapted = app(StorefrontCheckoutAdapter::class)->adapt($request, $store)) !== null) {
            return $adapted;
        }

        // P3-06: at a kiosk the name is optional ("We'll call this name"), there is no phone and an eat-in order has no table
        // number — the order number is the identity. The kiosk context is the server-side session marker, never a request field.
        $kiosk = KioskContext::on((int) $store->id);
        $rules = self::CHECKOUT_RULES;
        if ($kiosk) {
            $rules['name'] = 'nullable|string|max:255';
            $rules['phone-number'] = 'nullable|string|max:25';
            $request->merge(['name' => trim((string) $request->input('name')) !== '' ? trim((string) $request->input('name')) : ___('Guest')]);
            if ($request->get('ordering-type') === 'on-table' && trim((string) $request->input('table')) === '') {
                $request->merge(['table' => ___('Kiosk')]);
            }
        }
        $check = validator($request->all(), $rules);
        if ($check->fails()) {
            return $this->validationFailureJson($check);
        }

        // Pricing follow-ups (PRICING.md §3): a delivery order needs a plan that includes delivery.
        if ($request->get('ordering-type') === 'delivery' && ! PlanGate::storeAllows($store, PlanGate::DELIVERY)) {
            return response()->json(['success' => false, 'message' => PlanGate::customerMessage(PlanGate::DELIVERY)]);
        }

        // #76 / P6-348: block orders while the branch or the store is closed — the ONE
        // customer intake gate (OrderIntakeGate), shared with the customer app. A
        // scheduled (pre-)order may be placed while closed; a paused branch still refuses.
        $postedBranchId = (int) $request->get('branch_id');
        $orderBranch = $postedBranchId
            ? Branch::where('id', $postedBranchId)->where('store_id', $store->id)->first()
            : null;
        if (($closed = OrderIntakeGate::closedMessage($store, $orderBranch, $request->filled('scheduled_at'))) !== null) {
            return response()->json(['success' => false, 'message' => $closed]);
        }

        // F7: OTP checkout gate. When the restaurant requires phone verification
        // and this order carries an unverified phone, reject with otp_required so
        // the theme can switch to the OTP step (send → verify → re-submit). Skips
        // on-table orders, which don't collect a phone number.
        if (CheckoutOtpController::gateEnabled($store->id)
            && ! $kiosk
            && ! in_array($request->get('ordering-type'), ['on-table', 'room_service'], true)) {
            $gatePhone = app(Sms::class)
                ->normalizePhone((string) $request->get('phone-number'));
            if (! CheckoutOtpController::phoneIsVerified($gatePhone, (int) $store->id)) {
                return response()->json([
                    'success' => false,
                    'otp_required' => true,
                    'phone' => $gatePhone,
                    'message' => ___('Please verify your phone number to place this order.'),
                ]);
            }
        }

        $items = json_decode((string) $request->get('items'));
        if (! is_array($items)) {
            // T6: a combos-only cart posts no `items` at all. Anything else that is
            // not a list is still a malformed payload.
            if ($request->filled('items') || ! $request->filled('combos')) {
                return response()->json([
                    'success' => false,
                    'message' => ___('Invalid order payload.'),
                ]);
            }
            $items = [];
        }

        /* PARITY-RUN T6: combo lines arrive as their own JSON field, posted by the
           shared storefront partial (every theme inherits it) rather than by each
           theme's compiled cart bundle. Only ids travel — ComboService is the ONE
           place a combo is priced, and it re-derives the bundle price and every
           upcharge from the owner's own rows. */
        $postedCombos = json_decode((string) $request->get('combos'), true);
        $postedCombos = is_array($postedCombos) ? $postedCombos : [];

        // #76: resolve the formal branch (branch_id) if one was posted and belongs here.
        $orderBranch = null;
        $orderBranchId = (int) $request->get('branch_id');
        if ($orderBranchId) {
            $orderBranch = Branch::where('id', $orderBranchId)
                ->where('store_id', $store->id)->first();
        }

        $menusById = Menu::with('category')
            ->where('store_id', $store->id)
            ->whereIn('id', collect($items)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all())
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $menu = $menusById->get((int) ($item->id ?? 0));
            if (! $menu || ($orderBranch && ! $menu->availableAtBranch($orderBranch->id))) {
                return response()->json([
                    'success' => false,
                    'message' => ___('One or more selected items are not available for this branch.'),
                ]);
            }
        }

        /* T6: resolve + SERVER-price the combos before an Order row exists, so a
           tampered pick (an option from another combo, a slot under its minimum,
           a sold-out component) fails the whole submission rather than producing a
           half-priced order. ValidationException is translated into this
           controller's own {success:false,message} envelope, which is what every
           theme's cart already knows how to render. */
        $resolvedCombos = [];
        if ($postedCombos) {
            try {
                $resolvedCombos = ComboService::resolve(
                    (int) $store->id,
                    $postedCombos,
                    $orderBranch?->id
                );
            } catch (ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'message' => implode('<br>', $e->validator->errors()->all()),
                ]);
            }
        }

        /* An order has to contain SOMETHING. `items: []` is a well-formed empty
           list, so it slipped past the payload check above and produced a real
           order row with no lines — a blank kitchen ticket with a null total.
           Reached from a theme whose "Place order" stays live on an empty cart
           (crumb), and trivially by any direct POST. Combos-only carts post no
           `items` at all, so both baskets are checked together, after the combos
           are resolved. */
        if (! $items && ! $resolvedCombos) {
            return response()->json([
                'success' => false,
                'message' => ___('Your cart is empty.'),
            ]);
        }

        /* Line quantities were taken from the payload verbatim ($item->quantity,
           unsanitised) all the way into order_items and the amount sum, while
           only the minimum-order check clamped them. A posted `-4` line therefore
           SUBTRACTED from the total and from stock: `[{qty:5},{qty:-4}]` billed
           one unit, sent five to the kitchen and handed 4 back to inventory. A
           1,000,000 line was accepted just as happily. Combos already clamp
           (ComboService::resolve), so only these plain lines needed it.
           Rejected, not clamped: any such payload is tampered or a theme bug,
           and silently repricing it would hide both. */
        foreach ($items as $item) {
            $lineQty = $item->quantity ?? 1;
            if (! is_numeric($lineQty) || (int) $lineQty != $lineQty || (int) $lineQty < 1 || (int) $lineQty > 999) {
                return response()->json([
                    'success' => false,
                    'message' => ___('Invalid order payload.'),
                ]);
            }
            $item->quantity = (int) $lineQty;
        }

        // P1-23 (AUP 3.1): a pharmacy sells only when published, and never above the per-order cap of a medicine.
        $salesLines = [];
        foreach ($items as $item) {
            if ($menu = $menusById->get((int) ($item->id ?? 0))) {
                $salesLines[] = ['menu' => $menu, 'quantity' => (int) $item->quantity];
            }
        }
        if (($refusal = app(SalesRestrictions::class)->orderRefusal($store, $salesLines)) !== null) {
            return response()->json(['success' => false, 'message' => $refusal]);
        }

        // B8-P1: server-side minimum-order-value gate (was theme-side only). Skips
        // when unset/0 so restaurants without a minimum keep the old behavior.
        // Applies to all order types (takeaway, delivery, on-table, room_service).
        $minOrderValue = (float) (OrderIntakeGate::minimum($store, $orderBranch) ?? 0); // P5-31: per branch; P6-348: shared gate
        if ($minOrderValue > 0) {
            $subtotal = $this->minimumOrderSubtotal(
                $items,
                $resolvedCombos,
                $menusById,
                (string) $request->get('ordering-type'),
                $orderBranch?->id
            );
            if (($belowMin = OrderIntakeGate::belowMinimumMessage($store, $orderBranch, $subtotal)) !== null) {
                return response()->json(['success' => false, 'message' => $belowMin]);
            }
        }

        // T2: reject a bad/unredeemable gift-card code up-front (before creating the
        // order). Rate-limit invalid attempts to blunt code-guessing (abuse guard).
        if ($request->filled('gift_card_code')) {
            $gcKey = 'gc-attempt:'.$store->id.':'.$request->ip();
            if (RateLimiter::tooManyAttempts($gcKey, 10)) {
                return response()->json(['success' => false, 'message' => ___('Too many attempts. Please try again later.')]);
            }
            if (! GiftCardService::findRedeemable($request->get('gift_card_code'), $store->id)) {
                RateLimiter::hit($gcKey, 600); // 10/10min per IP+restaurant

                return response()->json(['success' => false, 'message' => ___('That gift card code is invalid.')]);
            }
        }

        /* P1-G: storefront checkout idempotency. The API path has carried
           `client_uuid` for a long time; this one had nothing, so a double-tapped
           "Place order", a browser retry on a flaky connection or a bfcache
           re-POST each created a SECOND real order — two kitchen tickets and two
           totals. The `restaurant-order` limiter is 10/minute, so two taps a
           second apart both sail through it.

           The shared order-field-forwarder mints the token and rotates it only
           after a successful response, so a retry of the same intent reuses it
           while the guest's NEXT order gets a fresh one. Scoped per restaurant:
           the same token in another store is a different order.

           J27-A2: the token (body client_order_id / client_uuid, or the Idempotency-Key header) must be a UUID
           (the forwarder mints a v7), and it is bound to the cart it first placed: a resend with ANOTHER cart is
           refused with 409, never answered with the first order as if it were the new one. */
        try {
            $clientUuid = ClientOrderId::fromRequest($request);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->validator->errors()->first()], 422);
        }
        $clientHash = $clientUuid ? ClientOrderId::fingerprint([
            'type' => $request->get('ordering-type'),
            'items' => json_decode((string) $request->get('items'), true),
            'combos' => $postedCombos,
        ]) : null;
        try {
            $replay = ClientOrderId::replay((int) $store->id, $clientUuid, $clientHash);
        } catch (ClientOrderConflict $e) {
            return $this->clientOrderConflictJson($e);
        }
        if ($replay) {
            return $this->clientOrderReplayJson($replay);
        }

        // P3-03 (REVIEW, Opus): an order sent from the offline queue is refused when any level switched the queue off,
        // when its type / payment may not queue or when it waited too long. Runs AFTER the replay answer above.
        if (($queueRefusal = app(OfflineQueuePolicy::class)->refusal($store, $request, (string) $request->get('ordering-type'))) !== null) {
            return $queueRefusal;
        }

        $orderingType = (string) $request->get('ordering-type');
        $guestName = $request->get('name');
        $guestPhone = $request->get('phone-number');
        $guestMessage = $request->get('message');

        $order = (new Order)->forceFill([
            'store_id' => $store->id,
            'client_uuid' => $clientUuid,
            'client_order_hash' => $clientHash,
            // The language the customer ordered in, so the track page speaks it
            // however it is reached later (notification links carry no locale).
            'locale' => get_lang(),
            'customer_id' => auth('customer')->id(),
            'type' => $orderingType,
            'customer_name' => $guestName,
        ]);
        // #76: record the formal branch (new branch_id system).
        if ($orderBranch) {
            $order->branch_id = $orderBranch->id;
        }

        $icon_menu_item = '▪️';
        $icon_menu_extra = '▫️';
        $icon_phone = '☎️';
        $icon_hash = '#️⃣';
        $icon_address = '📌';
        $icon_message = '📝';

        $orderTypeLabel = '';
        $branch_type = '';
        $amount = 0;
        $delivery_charge = 0;
        $taxx_charge = 0;
        $branch_whatsapp_number = '';
        $chargeToRoomStay = null; // T1 · H8: set in the room_service branch when charge-to-room is eligible
        // The guest's contact lines under their name in the owner's WhatsApp text.
        $contactLines = [];

        switch ($orderingType) {
            case 'on-table':
                $tableNumber = $request->get('table');

                /* PARITY-RUN T4: server-side twin of the scan-time resolve above. A
                   stale tab (or a hand-edited payload) naming a merged SECONDARY is
                   routed to the party's primary table, so a merge cannot silently
                   end up with two bills. Returns the input unchanged for anything it
                   cannot resolve, and is wrapped because table routing must never be
                   able to reject an order. */
                try {
                    $tableNumber = TableMergeService::normalizeTableNumber(
                        (int) $store->id,
                        $tableNumber,
                        $orderBranch->id ?? null
                    );
                } catch (\Throwable $e) {
                    // best-effort only — keep what the guest sent
                }

                /* P6-351: a kiosk order (on-table with an empty `table`, filled to
                   ___('Kiosk') above) has no real dine-in table — keep the old
                   "no table row, label survives" behaviour so kiosk flow doesn't
                   regress. The real dine-in path that follows tries to resolve a
                   StoreTable row so a non-numeric label sticks to service_location
                   and an unknown table is refused instead of being silently dropped. */
                if ($kiosk) {
                    $order->table_number = null;
                    $contactLines[] = $icon_hash.' '.((string) $tableNumber === '' ? ___('Kiosk') : (string) $tableNumber);
                    $orderTypeLabel = ___('On table');
                    break;
                }

                $tableRef = trim((string) $tableNumber);
                if ($tableRef !== '') {
                    /* Mirror StorefrontOrderPlacement::table() — same store, area_type
                       != room, scoped to the posted branch (or NULL branch when the
                       guest posted one), match by `key`, and only by `table_no` when
                       the post is all digits (an integer column: never compare text with
                       it, PostgreSQL). Unknown table -> a single fixed error message. */
                    $restaurantTable = StoreTable::query()
                        ->where('store_id', $store->id)
                        ->where('area_type', '!=', 'room')
                        ->when($orderBranch, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $orderBranch->id)->orWhereNull('branch_id')))
                        ->where(function ($q) use ($tableRef) {
                            $q->where('key', $tableRef);
                            if (ctype_digit($tableRef) && strlen($tableRef) < 9) {
                                $q->orWhere('table_no', (int) $tableRef);
                            }
                        })
                        ->first();

                    if (! $restaurantTable) {
                        return response()->json(['success' => false, 'message' => ___('This table was not found at this store.')]);
                    }

                    /* orders.table_number is an integer column: MySQL / PostgreSQL reject
                       a non-numeric label. A non-numeric table_no (a labelled table like
                       "A5") goes on service_location, the same way room_service writes its
                       room label. The WhatsApp/KOT contact line shows the label. */
                    $tableLabel = trim((string) ($restaurantTable->label ?: $restaurantTable->table_no));
                    $order->table_number = is_numeric($restaurantTable->table_no) ? (int) $restaurantTable->table_no : null;
                    if ($tableLabel !== '' && ! is_numeric($tableLabel)) {
                        $order->service_location = mb_substr($tableLabel, 0, 120);
                    }
                    $contactLines[] = $icon_hash.' '.($tableLabel !== '' ? $tableLabel : $restaurantTable->table_no);
                } else {
                    // Empty ref after normalization — keep parity with the old behaviour
                    // (numeric columns silently null) rather than failing a kiosk-flavoured shape.
                    $order->table_number = null;
                    $contactLines[] = $icon_hash.' ';
                }
                $orderTypeLabel = ___('On table');
                break;

            case 'room_service':
                /* T1/H2 hotel room service — in-room dining. Gated on hotel_mode.
                   The room is a store_tables row (area_type='room'); it arrives
                   either as the room QR key (`table`) or a manual room pick (`room`).
                   Fulfilment mirrors dine-in (no phone/address); the human-readable
                   room label is written to service_location. */
                if (! app(RoomCharge::class)->enabled($store->id)) {
                    return response()->json(['success' => false, 'message' => ___('Room service is not available.')]);
                }
                $roomKey = $request->get('room') ?: $request->get('table');
                $room = null;
                if ($roomKey !== null && $roomKey !== '') {
                    $room = StoreTable::query()
                        ->where('store_id', $store->id)
                        ->where('area_type', 'room')
                        /* A10: rooms are created with the dashboard sidebar's branch, which
                           is NULL under the "All branches" view. On a multi-branch store
                           whose storefront posts branch_id, a strict branch match made every
                           NULL-branch room unresolvable ("Please select your room.") for a
                           room the guest physically scanned. Tolerate unscoped rooms — the
                           per-restaurant `key` already identifies the room uniquely. */
                        ->when($orderBranch, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $orderBranch->id)->orWhereNull('branch_id')))
                        ->where(function ($q) use ($roomKey) {
                            $q->where('key', $roomKey);
                            if (ctype_digit((string) $roomKey)) {
                                $q->orWhere('id', (int) $roomKey);
                            }
                        })
                        ->first();
                }
                if (! $room) {
                    return response()->json(['success' => false, 'message' => ___('Please select your room.')]);
                }
                $roomLabel = $room->label ?: (string) $room->table_no;
                $order->service_location = mb_substr($roomLabel, 0, 120);
                $order->table_number = is_numeric($room->table_no) ? (int) $room->table_no : null; // legacy int field for KDS/printing; a non-numeric room label lives in service_location

                $contactLines[] = $icon_hash.' '.$roomLabel;

                $orderTypeLabel = ___('Room service');

                /* T1 · H8 charge-to-room tender. Eligible only when the guest picks it
                   AND an active stay exists on this room AND the entered name loosely
                   matches the stay guest (GloriaFood-style). Validate BEFORE the order
                   is saved so an ineligible request is rejected cleanly; the actual
                   folio post happens after the order items are attached (below). */
                if ($request->get('tender') === 'charge_to_room') {
                    /* J57: a charge-to-room order names its room by the QR key only — never the numeric room id
                       the manual room picker may send (ids are sequential, so anyone could pick any room). */
                    if (! is_string($roomKey) || ! hash_equals((string) $room->key, $roomKey)) {
                        return response()->json(['success' => false, 'message' => ___('Please scan the QR code in your room to charge this order to your room.')]);
                    }
                    /* B3 / J57 (hotel_charge_room_token_required, ON by default since J57): defence-in-depth on
                       top of the name gate. When enabled, the charge must carry the signed
                       token from the room's QR (stashed in the session on scan) matching the
                       room being charged — so a diner cannot pick an arbitrary room by its
                       guessable number. Fail-closed: no token / a token for another room is
                       refused BEFORE the order is saved, so no folio ledger is posted. Off by
                       default (owner flips it once the printed QR codes carry the token). */
                    if (app(RoomCharge::class)->tokenRequired((int) $store->id)
                        && ! app(RoomCharge::class)->verifyToken($room, session('hotel_room_token'))) {
                        return response()->json(['success' => false, 'message' => ___('Please scan the QR code in your room to charge this order to your room.')]);
                    }
                    // BL-050: the guest's own stay (stay token, else unambiguous by name), never the room's newest stay.
                    $stayToken = session('hotel_stay_token');
                    $chargeToRoomStay = app(RoomCharge::class)->stayForGuest((int) $store->id, (int) $room->id, is_string($guestName) ? $guestName : null, is_string($stayToken) ? $stayToken : null);
                    if (! $chargeToRoomStay) {
                        return response()->json(['success' => false, 'message' => ___('No active stay on this room to charge to. Please pay another way.')]);
                    }
                    if (! app(RoomCharge::class)->guestNameMatches($chargeToRoomStay, $guestName)) {
                        return response()->json(['success' => false, 'message' => ___('The name does not match this room’s registered guest.')]);
                    }
                }
                break;

            case 'takeaway':
                $order->phone_number = $guestPhone;
                $contactLines[] = $icon_phone.' '.$guestPhone;
                $orderTypeLabel = ___('Takeaway');
                break;

            case 'delivery':
                $deliveryAddress = $request->get('address');
                $order->phone_number = $guestPhone;
                $order->address = $deliveryAddress;

                /* Delivery pin — the lat/lng a diner set via a saved-address
                   chip, the cart map, or "use my location". Persist onto the
                   order so delivery dispatch (DispatchService) has real
                   coordinates; without this the pin died in the DOM and every
                   storefront delivery order reached the driver with none.
                   Theme input names vary, so normalise (address_lat/lng,
                   latitude/longitude, delivery_lat/lng). */
                [$deliveryLat, $deliveryLng] = storefront_delivery_coords($request->all());
                // The store's delivery radius, checked here (server side) on the pin: no pin when a radius applies is a
                // refusal too, or a shopper could skip the check by not dropping one.
                $radiusSlots = app(DeliverySlotBook::class);
                $radiusBranch = $order->branch_id ? Branch::where('id', $order->branch_id)->where('store_id', $store->id)->first() : null;
                if ($radiusSlots->radiusApplies($store, $radiusBranch)) {
                    if ($deliveryLat === null) {
                        return response()->json(['success' => false, 'code' => 'pin_required', 'message' => ___('Drop a pin on the map so we can check that we deliver to you.')]);
                    }
                    if ($radiusSlots->inRadius($store, $radiusBranch, ['lat' => $deliveryLat, 'lng' => $deliveryLng]) === false) {
                        return response()->json(['success' => false, 'code' => 'out_of_zone', 'message' => ___('This address is outside our delivery area.')]);
                    }
                }
                if ($deliveryLat !== null) {
                    $order->delivery_lat = $deliveryLat;
                    $order->delivery_lng = $deliveryLng;
                }

                $contactLines[] = $icon_phone.' '.$guestPhone;
                $contactLines[] = $icon_address.' '.$deliveryAddress;
                // A tappable maps link to the diner's pin, so whoever delivers gets a
                // real map from the owner's WhatsApp/notification, not just the text.
                if ($deliveryLat !== null && ($mapsUrl = order_delivery_maps_url($order))) {
                    $contactLines[] = $icon_address.' '.$mapsUrl;
                }
                $orderTypeLabel = ___('Delivery');
                // options saved as empty strings must not reach float math (PHP 8 TypeError)
                $delivery_charge = Branch::effectiveDeliveryFee((int) $store->id, $order->pricingBranch()); // P5-31
                break;
        }
        $taxx_charge = (float) (store_options($store->id, 'restaurant_taxx_charge', 0) ?: 0);

        // Owner-facing customer block: name, the contact lines, then the note.
        $customerDetails = $guestName."\n".implode("\n", $contactLines);
        if (! empty($_POST['message'])) {
            $customerDetails .= sprintf("\n%s %s\n", $icon_message, $guestMessage);
        }

        /* #76: branch label + WhatsApp number from the new branch_id system.

           WA-D4: the fallback to the legacy option is logged. This is the evidence
           Lane E needs — if nothing hits it for a while, deleting whatsapp_number
           is provably safe rather than merely hopeful. The branch path is the
           normal one and is not logged. */
        if ($orderBranch) {
            $branch_type = $orderBranch->name;
        }

        /* WA-E3: resolve through the one helper instead of re-implementing the
           chain here. This path used to read the legacy option directly and had NO
           final fallback to the store's own phone — so the moment E3 deleted the
           options, an order that carried no branch produced `phone=` with nothing
           after it. A real order (#99804) did exactly that.

           storefront_whatsapp_number() is the same resolver the storefront chat
           button uses: the branch in context, then the store's default branch, then
           the legacy option (logged), then the store phone. One chain, one place. */
        $branch_whatsapp_number = storefront_whatsapp_number($store, $orderBranch ?: null);

        // T5 curbside: a takeaway order can be curbside with vehicle details.
        // FIX-2 F2-3: creating a curbside order is the other entry point — a store
        // whose owner is not entitled must not be able to mint one, or the arrival
        // ping later 403s on an order the diner was told was curbside.
        if ($order->type === 'takeaway'
            && $request->boolean('curbside')
            && self::curbsideAllowed((int) $store->id)
            && ! empty(store_options($store->id, 'curbside_enabled'))) {
            $order->is_curbside = true;
            $vehicle = trim(implode(' ', array_filter([
                $request->get('vehicle_color'),
                $request->get('vehicle_make'),
                $request->get('vehicle_plate') ? '('.$request->get('vehicle_plate').')' : null,
            ])));
            $order->vehicle_details = $vehicle !== '' ? mb_substr($vehicle, 0, 191) : null;
        }

        $order->message = $guestMessage;
        // K4: never trust the posted tip. Clamp non-negative and cap at a sane
        // absolute ceiling so a malformed/hostile client can't post an absurd tip
        // (the total contract already adds tip_amount — Order::getTotalAttribute).
        $order->tip_amount = max(0.0, min(
            (float) $request->get('tip_amount', 0),
            (float) config('settings.max_tip_amount', 100000)
        ));
        $order->track_token = Str::random(32);
        $order->created_at = now();
        // #18 / T1: Pre-order scheduling — validate against restaurant rules
        // (enabled, lead time, horizon, opening hours, per-slot capacity) and
        // compute the kitchen fire time. ASAP orders pass through as null.
        try {
            $scheduling = app(SchedulingService::class);
            // Coerce to a scalar before validate()'s ?string param: a crafted
            // scheduled_at[]=x posts an array, which would raise a TypeError (not the
            // InvalidArgumentException the catch below handles) and 500 the checkout.
            // A non-scalar is meaningless as a time → treat as ASAP (null).
            $scheduledRaw = $request->get('scheduled_at');
            $scheduledRaw = is_scalar($scheduledRaw) ? (string) $scheduledRaw : null;
            $scheduledAt = $scheduling->validate(
                $store->id,
                $orderBranch,
                $order->type,
                $scheduledRaw
            );
            if ($scheduledAt) {
                $order->scheduled_at = $scheduledAt;
                $order->fire_at = $scheduling->computeFireAt($store->id, $scheduledAt);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }

        /* A2: a guest who leaves 'Pay online' selected AND ticks 'Charge to my room'
           submits both — nothing makes the theme's payment radios and the hotel
           partial's charge-to-room checkbox mutually exclusive. Stamping 'unpaid'
           here would exclude the order from KDS/owner lists forever (no gateway
           Transaction is created for a charge-to-room order, so nothing flips it
           back), while the folio is still billed. When the charge-to-room tender
           resolved ($chargeToRoomStay set at :2267), the folio is the tender — never
           mark it unpaid. (chargeOrderForTender also clears any residual 'unpaid'.) */
        if ($request->get('pay_via') == 'pay_online' && ! $chargeToRoomStay) {
            $order->status = 'unpaid';
        }

        // B9: tag in-store kiosk orders so KDS/analytics can split the channel.
        // The kiosk context is SERVER-side (set by the {slug}/kiosk route, cleared by the
        // staff exit PIN) — a `kiosk_mode` request field is never trusted.
        if (KioskContext::active((int) $store->id)
            && feature_enabled('kiosk')
            && ! empty(store_options($store->id, 'kiosk_enabled'))) {
            $order->source = 'kiosk';
        }

        /* PARITY-RUN T3 — `staff_approve` dine-in verification. The scan gate
           (EnsureTableSessionVerified) stamped this attribute; the order is
           created exactly as always and then parked pre-KOT. Order::scopeLive()
           keeps it out of every live staff queue and KdsService::syncOrderStatus()
           refuses to derive over it, so nothing reaches the kitchen until a human
           approves. An online-payment order keeps 'unpaid' — money first, then
           the approval decision on the POS approvals queue. */
        if ($request->attributes->get(EnsureTableSessionVerified::ATTR_PENDING_APPROVAL)
            && $order->status !== 'unpaid') {
            $order->status = Order::STATUS_PENDING_APPROVAL;
        }

        $deferredTranscribe = null;   // H15/H6: dispatched after commit (set by whichever branch runs)
        $couponDeclined = false;      // additive UI hint; set by whichever branch runs

        /* H15: which code places this storefront order. Default legacy (dark ship —
           `storefront_order_pipeline`, read via storefront_unified_pipeline()). The
           rollout counter is a GAUGE, not a metric: it lives in the evictable cache
           and will under-report if the driver evicts it. Nothing depends on it being
           exact and it adds no DB write to the platform's busiest endpoint, so do NOT
           "fix" it into an orders column. */
        /* P6-05 (item 26): the customer a live kiosk of THIS store recognised (phone code / pass QR, server-side session,
           10 min) owns the kiosk order, so its points earn and the "use my points" reward redeems through the ONE
           loyalty path. Never a request field; never the store-credit wallet on a shared tablet. */
        $kioskCustomerId = null;
        if (! auth('customer')->check() && $order->source === 'kiosk' && KioskContext::on((int) $store->id)
            && KioskLoyalty::enabled((int) $store->id)) {
            $kioskCustomerId = app(KioskLoyalty::class)->current($store, $request->session())?->id;
        }
        $unified = storefront_unified_pipeline((int) $store->id);
        try {
            \Cache::increment('sf_pipeline:'.($unified ? 'unified' : 'legacy').':'.now()->format('Ymd'));
        } catch (\Throwable $e) {
        }

        /* Names of lines that were sold out / outside their availability window at
           submit time and were silently dropped from the order. Both pipelines
           remove such lines and place the rest; collecting the names lets the
           checkout tell the diner what was left off instead of them noticing a
           missing item on the receipt. Populated in each branch's item pass. */
        $droppedItems = [];

        if ($unified) {
            /* H15 unified pipeline: persistence + pricing move into
               OrderApiService::create(). Gates, sanitisation and the type / branch /
               table / scheduling / status resolution above have already run on
               $order; ONLY persistence moves. Storefront-only follow-ups (voice note,
               customer capture, loyalty redeem) run here after create() returns; the
               comms / email / SMS / WhatsApp / folio / Transaction blocks stay in the
               shared post-branch region below and run for both branches. */

            // Storefront-only columns create() does not model — captured from the
            // order built above and re-applied after create() (mirrors
            // CrossCartService::checkout's delivery_lat/lng follow-up write).
            $sfServiceLocation = $order->service_location;
            $sfIsCurbside = $order->is_curbside;
            $sfVehicleDetails = $order->vehicle_details;
            $sfTableNumber = $order->table_number;      // room->table_no / normalised on-table
            $sfScheduledAt = $order->scheduled_at;       // already validated above
            $sfSource = $order->source;                  // 'kiosk' when tagged, else null
            $sfTip = $order->tip_amount;                 // already clamped above
            $sfType = $order->type;
            $sfDeliveryLat = $order->delivery_lat;       // delivery pin, set in the delivery branch
            $sfDeliveryLng = $order->delivery_lng;
            $sfStatusOverride = in_array($order->status, ['unpaid', Order::STATUS_PENDING_APPROVAL], true)
                ? $order->status : null;

            /* §3.4 step 8 — availability pre-filter (behaviour-preserving). The
               legacy loop SILENTLY SKIPS a missing / sold-out / out-of-window line
               and places the rest; OrderApiService::resolveLines THROWS on the same
               line for a guest source. Drop them with the SAME predicate the legacy
               loop uses (StoreController's old :2599-2610), then re-run the empty-cart
               guard, so a partially-fulfillable order stays partially-fulfillable
               instead of hard-failing on the busiest endpoint. */
            // Bug batch 7 · 3/4: shared with the coupon pre-flight (applyCoupon), so a preview prices the same lines.
            $unifiedLines = LegacyCartLines::lines($items, $store, $sfType, $droppedItems);
            if (! $unifiedLines && ! $resolvedCombos) {
                return response()->json(['success' => false, 'message' => ___('Your cart is empty.')]);
            }

            /* P1-G idempotency (unified branch): OrderApiService::create() dedups the
               ORDER row on a repeated client_uuid but returns the existing order
               SILENTLY, so a double-tap / retried POST would fall through to the shared
               tail and re-apply the once-per-order side effects — loyalty redemption
               (customer points debited again, an extra reward added to discount_amount)
               and the CRM orders_count / total_spent aggregates. The legacy branch below
               returns early on its unique-index throw; mirror that here BEFORE create(),
               so a resubmit is a no-op that still shows the guest success. */
            try {
                $dupeOrder = ClientOrderId::replay((int) $store->id, $clientUuid, $clientHash);
            } catch (ClientOrderConflict $e) {
                return $this->clientOrderConflictJson($e);
            }
            if ($dupeOrder) {
                return $this->clientOrderReplayJson($dupeOrder);
            }

            /* TOCTOU: this create() call takes the `scheduled_at_trusted` path, which
               does NOT acquire the per-slot capacity lock — validate() counted the slot
               far above, but the INSERT lands here, so two concurrent bookers could both
               pass and overfill a capacity-limited pre-order window. Hold the SAME slot
               lock OrderApiService uses across create()'s INSERT+commit; released in the
               finally below. No-op for ASAP / unlimited-capacity stores. */
            try {
                $sfSlotLock = $scheduling->lockSlot((int) $store->id, $orderBranch, $scheduledAt);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()]);
            }
            try {
                [$order, $sfReplayed] = OrderApiService::createOrReplay((int) $store->user_id, (int) $store->id, [
                    'client_uuid' => $clientUuid,
                    'client_order_hash' => $clientHash,
                    'type' => $sfType,
                    'source' => $sfSource ?: 'qr',
                    'branch_id' => $orderBranch?->id,
                    'customer_id' => auth('customer')->id() ?? $kioskCustomerId,
                    'customer_name' => $guestName,
                    'phone' => $guestPhone,
                    'address' => $request->get('address'),
                    'table_number' => $sfTableNumber,           // create() keeps it for on-table only
                    'note' => $guestMessage,
                    'tip_amount' => $sfTip,                      // already clamped above
                    'scheduled_at_trusted' => optional($sfScheduledAt)->format('Y-m-d H:i:s'),
                    'items' => $unifiedLines,
                    'combos' => $postedCombos,                   // re-resolved inside create()
                    'coupon_code' => $request->filled('coupon_code') ? $request->get('coupon_code') : null,
                    'use_wallet' => $kioskCustomerId === null && $request->boolean('use_wallet'),
                    'gift_card_code' => $request->filled('gift_card_code') ? $request->get('gift_card_code') : null,
                    /* K3 (A1 fix): loyalty redemption is owned by create() now, so it
                       is folded into discount_amount BEFORE the wallet/gift-card tenders
                       are sized (otherwise a full-balance wallet over-drains and
                       amount_due goes negative). create() re-checks the feature gate,
                       the active program and the customer balance server-side. */
                    'loyalty_redeem' => $request->boolean('loyalty_redeem'),
                    /* The storefront fires its own order.created webhook (different
                       payload shape) and its own Web Push job below, so create() must
                       not fire duplicates. KDS sync + auto-print are deliberately NOT
                       suppressed — closing those two gaps is the point of H15. */
                    'side_effects' => ['webhook' => false, 'staff_push' => false],
                ]);
            } catch (ValidationException $e) {
                return response()->json([
                    'success' => false,
                    'message' => implode('<br>', $e->validator->errors()->all()),
                ]);
            } catch (ClientOrderConflict $e) {
                return $this->clientOrderConflictJson($e);
            } finally {
                // Order row is committed (create() runs its own transaction) — free the slot.
                optional($sfSlotLock ?? null)->release();
            }

            /* J27-A2: a concurrent twin with the same token won the insert (create() recovered the unique
               violation) — the storefront tail below must not run twice for one order. */
            if ($sfReplayed) {
                return $this->clientOrderReplayJson($order);
            }

            // Storefront-only columns create() does not model.
            $order->forceFill(array_filter([
                'service_location' => $sfServiceLocation,
                'is_curbside' => $sfIsCurbside,
                'vehicle_details' => $sfVehicleDetails,
                // room_service keeps table_number = room->table_no, which create()
                // nulls for a non-on-table type. (Read the request value, not the
                // narrowed $order->type, whose annotated union omits room_service.)
                'table_number' => $request->get('ordering-type') === 'room_service' ? $sfTableNumber : null,
                'status' => $sfStatusOverride,   // 'unpaid' (pay online) | pending-approval
                'delivery_lat' => $sfDeliveryLat, // delivery pin (create() doesn't model it)
                'delivery_lng' => $sfDeliveryLng,
                // A10: the legacy path stamps $order->locale = get_lang() (:2166) so the
                // track page (reached later from links carrying no locale) speaks the
                // order's language. OrderApiService::create builds a fresh Order and
                // never sets locale, so unified-pipeline orders — hotel breakfast
                // pre-orders included — landed with locale NULL, regressing the fixed
                // track-page order-locale trap. Mirror the legacy stamp here.
                'locale' => get_lang(),
            ], fn ($v) => $v !== null))->save();

            /* H15: persistLines writes order_note = the posted line note only, but the
               legacy storefront also folds the chosen modifier lines into order_note
               — the kitchen ticket, the KDS and the owner's order screen read it (old
               :2752). Rebuild that text from the persisted `options` snapshot so those
               surfaces render identically. Combo lines are skipped (their `options`
               are the bundle snapshot, not modifier picks). */
            $noteDecimals = currency_decimals(store_options($store->id, 'currency_code') ?: null);
            $order->load('items');
            foreach ($order->items as $li) {
                if ($li->isCombo() || empty($li->options)) {
                    continue;
                }
                $modLines = [];
                foreach ((array) $li->options as $grp) {
                    foreach ((array) ($grp['selected'] ?? []) as $opt) {
                        $optPrice = (float) ($opt['price'] ?? 0);
                        $modLines[] = (string) ($grp['title'] ?? '').': '.(string) ($opt['option'] ?? '')
                            .($optPrice > 0 ? ' (+'.number_format($optPrice, $noteDecimals).')' : '');
                    }
                }
                if ($modLines) {
                    $existingNote = $li->order_note;
                    $li->update([
                        'order_note' => trim(($existingNote ? $existingNote."\n" : '').implode("\n", $modLines)),
                    ]);
                }
            }

            // FEATURES-5 Track C: attach a pre-uploaded voice note; queue the
            // transcript fill after the response. Best-effort (mirrors the legacy
            // block; runs after create() since it needs the persisted order id).
            try {
                $voiceToken = trim((string) $request->get('voice_note_token'));
                if ($voiceToken !== '' && VoiceNoteController::acceptEnabled($store->id)) {
                    $voiceAttached = OrderVoiceNote::where('token', $voiceToken)
                        ->where('store_id', $store->id)
                        ->whereNull('order_id')
                        ->limit(1)
                        ->update(['order_id' => $order->id]);
                    if ($voiceAttached && TranscribeVoiceNote::enabledFor($store->id)) {
                        $voiceNoteId = (int) OrderVoiceNote::where('token', $voiceToken)
                            ->where('order_id', $order->id)->value('id');
                        if ($voiceNoteId) {
                            $deferredTranscribe = new TranscribeVoiceNote($voiceNoteId);
                        }
                    }
                }
            } catch (\Throwable $e) {
            }

            // #56: Customer capture (guest) — mirrors the legacy block; sets
            // order->customer_id so the aggregate + loyalty steps see it.
            if (! auth('customer')->check() && $kioskCustomerId === null) {
                $captureName = $guestName;
                $capturePhone = $guestPhone;
                $captureEmail = filter_var($request->get('capture_email', ''), FILTER_VALIDATE_EMAIL) ?: null;
                $consented = $request->boolean('marketing_consent');
                $smsConsented = $request->boolean('sms_consent');

                if ($capturePhone || $captureEmail) {
                    $lookup = null;
                    if ($captureEmail) {
                        $lookup = Customer::where('store_id', $store->id)
                            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $captureEmail))])->first();
                    }
                    if (! $lookup && $capturePhone) {
                        $lookup = Customer::where('store_id', $store->id)
                            ->where('phone', $capturePhone)->first();
                    }

                    $captureData = [
                        'store_id' => $store->id,
                        'name' => strip_tags($captureName ?? ''),
                        'email' => $captureEmail,
                        'phone' => $capturePhone ? strip_tags($capturePhone) : null,
                        'source' => 'checkout',
                    ];
                    if ($consented) {
                        $captureData['marketing_consent'] = true;
                        $captureData['consent_at'] = now();
                    }
                    if ($smsConsented) {
                        $captureData['sms_consent'] = true;
                        $captureData['consent_at'] = $captureData['consent_at'] ?? now();
                    }

                    if ($lookup) {
                        $lookup->fill(array_filter($captureData, fn ($v) => $v !== null && $v !== ''))->save();
                        if (! $order->customer_id) {
                            $order->customer_id = $lookup->id;
                            $order->save();
                        }
                    } else {
                        $newCustomer = Customer::create(array_merge(['marketing_consent' => false], $captureData));
                        if (! $order->customer_id) {
                            $order->customer_id = $newCustomer->id;
                            $order->save();
                        }
                    }
                }
            }

            /* K3 (A1 fix): loyalty redemption moved INTO OrderApiService::create()
               (folded before the wallet/gift-card tenders), so there is no
               post-create loyalty step here — applying it after the tenders drove
               amount_due negative and over-drained store credit. */

            /* §3.4 step 11 — rebuild the presentation locals from the PERSISTED
               order (better than the write-loop accumulation: it describes what the
               order actually totals to). $amount is the ONE total authority; the
               controller keeps NO $subtotal+delivery+tax+tip-discount arithmetic. */
            $order = $order->fresh()->load(['items.menu', 'items.variant', 'items.itemExtras.extra']);
            $text = OrderTextRenderer::render($order);
            $ownerOrderText = $text['msg'];
            $whatsappOrderLines = $text['whatsapp'];
            $amount = (float) $order->total;
            $couponDeclined = false; // create() does not surface claim_denied (additive hint only)
        } else {
            /* H6: order + lines + combos + pricing write inside ONE transaction
               (kill switch platform.orders.atomic_create, default on — the SAME switch
               OrderApiService::atomically() reads, so it can never apply to one path
               and not the other). A mid-flight throw used to leave a committed orders
               row with only the lines that happened to persist, at a wrong-but-plausible
               re-derived total the KDS and printer accepted. The five external side
               effects (voice transcribe, metering, webhook, staff push, Meta CAPI) move
               to AFTER commit — they previously fired here, before the item loop, so
               every storefront order reported a ZERO-basket total to its webhook/push/
               Purchase event. Imperative begin/commit is used (not the closure form) so
               the ~500-line span keeps every local inline. */
            $atomicCreate = config('platform.orders.atomic_create', true);
            $deferredTranscribe = null;   // H6: dispatched after commit
            /* TOCTOU: the legacy path sets $order->scheduled_at far above and INSERTs it
               below without any capacity guard — two concurrent bookers of the same
               capacity-limited slot both pass validate()'s non-atomic count. Hold the
               SAME per-slot lock OrderApiService uses, from before the INSERT until the
               transaction commits (released in the finally on the try below). No-op for
               ASAP / unlimited-capacity stores. */
            try {
                $sfSlotLock = $scheduling->lockSlot((int) $store->id, $orderBranch, $scheduledAt);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()]);
            }
            if ($atomicCreate) {
                \DB::beginTransaction();
            }
            try {
                /* P1-G: the SELECT above narrows the window, but two simultaneous taps
                   can both pass it. The unique index orders_rid_cuuid_uniq makes the
                   loser's INSERT fail — return the winning order instead of a 500. */
                try {
                    // Savepoint: on PostgreSQL a failed insert would abort the enclosing transaction.
                    \DB::transaction(fn () => $order->save());
                } catch (QueryException $e) {
                    $winner = $clientUuid
                        ? Order::where('store_id', $store->id)->where('client_uuid', $clientUuid)->first()
                        : null;
                    if (! $winner) {
                        throw $e;
                    }

                    // H6: nothing was written; commit the empty transaction and render
                    // the duplicate response WITHOUT re-firing any post-commit side effect.
                    if ($atomicCreate) {
                        \DB::commit();
                    }

                    try {
                        ClientOrderId::assertSameCart($winner, $clientHash); // J27-A2
                    } catch (ClientOrderConflict $conflict) {
                        return $this->clientOrderConflictJson($conflict);
                    }

                    return $this->clientOrderReplayJson($winner);
                }

                // FEATURES-5 Track C: attach a pre-uploaded customer voice note (by temp
                // token) to the freshly-created order. Best-effort — a bad/foreign token
                // never blocks the order.
                try {
                    $voiceToken = trim((string) $request->get('voice_note_token'));
                    if ($voiceToken !== '' && VoiceNoteController::acceptEnabled($store->id)) {
                        $voiceAttached = OrderVoiceNote::where('token', $voiceToken)
                            ->where('store_id', $store->id)
                            ->whereNull('order_id')
                            ->limit(1)
                            ->update(['order_id' => $order->id]);

                        // ZERO-TOUCH Track B3: queue the transcript fill (owner toggle +
                        // provider capability re-checked inside the job). afterResponse
                        // on sync/no-worker hosts so checkout latency never pays for STT;
                        // failures inside the job are logged, never block the order.
                        if ($voiceAttached && TranscribeVoiceNote::enabledFor($store->id)) {
                            $voiceNoteId = (int) OrderVoiceNote::where('token', $voiceToken)
                                ->where('order_id', $order->id)->value('id');
                            if ($voiceNoteId) {
                                // H6: build the job here (inside the tx, where the note is
                                // attached) but DISPATCH it after commit.
                                $deferredTranscribe = new TranscribeVoiceNote($voiceNoteId);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                }

                // H6: metering, webhook, staff push and Meta CAPI moved to AFTER commit —
                // they must not fire on a rolled-back order, and firing them here (before
                // the item loop) reported a zero-basket total. See the block after the tx.

                // #56: Customer capture — upsert a customer record with optional marketing consent
                if ($kioskCustomerId !== null) {
                    $order->customer_id = $kioskCustomerId; // P6-05: the kiosk's recognised customer (proved by code / pass)
                    $order->save();
                } elseif (! auth('customer')->check()) {
                    $captureName = $guestName;
                    $capturePhone = $guestPhone;
                    $captureEmail = filter_var($request->get('capture_email', ''), FILTER_VALIDATE_EMAIL) ?: null;
                    $consented = $request->boolean('marketing_consent');
                    $smsConsented = $request->boolean('sms_consent'); // #104: channel-specific

                    if ($capturePhone || $captureEmail) {
                        $lookup = null;
                        if ($captureEmail) {
                            $lookup = Customer::where('store_id', $store->id)
                                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $captureEmail))])->first();
                        }
                        if (! $lookup && $capturePhone) {
                            $lookup = Customer::where('store_id', $store->id)
                                ->where('phone', $capturePhone)->first();
                        }

                        $data = [
                            'store_id' => $store->id,
                            'name' => strip_tags($captureName ?? ''),
                            'email' => $captureEmail,
                            'phone' => $capturePhone ? strip_tags($capturePhone) : null,
                            'source' => 'checkout',
                        ];
                        if ($consented) {
                            $data['marketing_consent'] = true;
                            $data['consent_at'] = now();
                        }
                        if ($smsConsented) {
                            $data['sms_consent'] = true;
                            $data['consent_at'] = $data['consent_at'] ?? now();
                        }

                        if ($lookup) {
                            $lookup->fill(array_filter($data, fn ($v) => $v !== null && $v !== ''))->save();
                            // GOL: link the guest order to the captured customer. The old
                            // `isset($order->customer_id) && !` guard was dead — isset() is
                            // FALSE for a null Eloquent attribute, so a legacy-pipeline guest
                            // order NEVER got customer_id, leaving it unlinked from its shadow
                            // (and breaking the register-adopt proof). empty() links it.
                            if (empty($order->customer_id)) {
                                $order->customer_id = $lookup->id;
                                $order->save();
                            }
                        } else {
                            $newCustomer = Customer::create(array_merge(['marketing_consent' => false], $data));
                            if (empty($order->customer_id)) {
                                $order->customer_id = $newCustomer->id;
                                $order->save();
                            }
                        }
                    }
                }

                $ownerOrderText = '';
                $whatsappOrderLines = '';

                /* Enforce scheduled availability server-side (not just client-side in the
                   theme): reject items that are sold out or outside their available
                   window. PARITY-RUN T5 moved the timezone resolution into
                   MenuScheduleService, which memoises it per request and also folds in
                   the category window and the order-type scope. */

                $placedLines = 0; // lines that really landed on the order (an all-dropped cart must not become an empty order)
                foreach ($items as $item) {
                    $itemId = $item->id;
                    $units = $item->quantity;
                    // H6: a line without a `variants` key used to raise an "Undefined
                    // property" ErrorException here. Before this item that throw left a
                    // committed order+customer with NO lines (the exact half-written
                    // order this WI fixes); now the transaction would roll the whole
                    // order back. Default to 0 (no variant) so a complete order is
                    // placed instead of a 500. `extras` guarded likewise below.
                    $variants = $item->variants ?? 0;

                    // P4-04: only THIS store's item (a cart naming another store's item id was attached to this order and took that
                    // store's stock); a foreign id is dropped like any item that is not on this menu.
                    $menu = Menu::query()->where('store_id', $store->id)->whereKey($itemId)->first();
                    if ($menu && $menu->is_sold_out) {
                        // Skip sold out items (record the name so the diner is told)
                        $droppedItems[] = (string) $menu->name;

                        continue;
                    }
                    /* PARITY-RUN T5: the item's own window, its CATEGORY's window and
                       the order-type scope, all on the store's clock. Same resolver
                       the storefront rendered from, so a diner can never check out
                       something the menu just stopped offering. */
                    if ($menu && ! MenuScheduleService::isAvailable($menu, null, $order->type)) {
                        // Skip items ordered outside their scheduled availability window
                        $droppedItems[] = (string) $menu->name;

                        continue;
                    }

                    /* P4-04: a customer cannot order more of a stock-tracked item than the shelf has (the unified pipeline and the
                       API already refuse it; this default pipeline took the stock negative). The take below is ONE conditional
                       UPDATE, so two shoppers racing for the last unit cannot both get it. Staff channels (POS) keep recording. */
                    if ($menu && $menu->track_inventory && $menu->stock_qty !== null && inventory_mode($store->id) !== 'advanced'
                        && (int) $menu->stock_qty < (int) $units) {
                        throw ValidationException::withMessages([
                            'items' => ___(':item does not have enough stock left.', ['item' => (string) $menu->getRawOriginal('name')]),
                        ]);
                    }

                    if ($menu) {
                        // #77: route to kitchen station (per-branch override → menu default), copy course.
                        $stationId = KdsService::resolveStationForOrderItem($order->branch_id ?: null, $menu);
                        $itemCourse = (int) ($menu->course ?? 1);

                        /* PARITY-RUN T5: resolve the scheduled price HERE, on the server,
                           and snapshot it onto the line. The cart never quotes money the
                           server will not charge, and a receipt reprinted after the happy
                           hour ends still re-totals to what was actually paid. BL-049:
                           unit_price is always set, so a later menu price edit never
                           re-totals the order. */
                        $chosenVariant = is_numeric($variants)
                            ? MenuVariant::where('menu_id', $itemId)->find($variants)
                            : null;
                        $priceSnapshot = MenuScheduleService::snapshotFor(
                            $menu,
                            $chosenVariant,
                            $order->type,
                            $order->branch_id ?: null
                        );

                        $orderLine = OrderItem::create(array_merge([
                            'order_id' => $order->id,
                            'item_id' => $itemId,
                            'station_id' => $stationId,
                            'status' => 'queued',
                            'course' => $itemCourse,
                            'fired_at' => $itemCourse <= 1 ? now() : null, // course 1 fires now; later courses are held
                            'quantity' => $units,
                            // the posted variant id as sent (0 = none), even when it no longer resolves
                            'variation' => is_numeric($variants) ? $variants : 0,
                            'order_note' => $item->order_note ?? null,
                        ], $priceSnapshot));
                        $placedLines++;
                        // Simple mode: take the units (a no-op for an untracked item) or refuse the whole order (rolled back, nothing taken).
                        // P6-103: the chosen variant's own stock cell too (a null stock_qty is a no-op), exactly like
                        // OrderApiService::persistLines — before, the storefront never took it, so two shoppers both got
                        // the last size/colour and every cancel gave back units that were never taken.
                        if (inventory_mode($store->id) !== 'advanced'
                            && (! Menu::reserveStock((int) $itemId, (int) $units)
                                || ($chosenVariant && ! MenuVariant::reserveStock((int) $chosenVariant->id, (int) $units)))) {
                            throw ValidationException::withMessages([
                                'items' => ___(':item does not have enough stock left.', ['item' => (string) $menu->getRawOriginal('name')]),
                            ]);
                        }
                        // Deduct stock inventory — advanced (ingredient/recipe) or simple (per-item)
                        try {
                            if (inventory_mode($store->id) === 'advanced') {
                                InventoryService::deductForOrderItem((int) $itemId, (int) $units, $order->id, $order->branch_id ?: null);
                            }
                        } catch (\Throwable $e) {
                            // H6: inside the order transaction, a swallowed deadlock (1213) or
                            // lock-wait timeout (1205) leaves every later statement failing
                            // against a dead transaction. Re-throw exactly those so the order
                            // rolls back cleanly; keep swallowing everything else (a missing
                            // recipe must never fail an order). sqlite never reports 1213/1205.
                            if ($e instanceof QueryException
                                && in_array((int) ($e->errorInfo[1] ?? 0), [1213, 1205], true)) {
                                throw $e;
                            }
                        }

                        // A resolved variant prices the line and names it: "Burger (Large)".
                        $variantSuffix = '';
                        if ($chosenVariant) {
                            $menu->price = $chosenVariant->price;
                            $variantSuffix = $this->variantTitleSuffix($chosenVariant);
                        }

                        /* PARITY-RUN T5: the confirmation/WhatsApp running total must
                           use the SAME snapshotted price the order line carries, or the
                           message a guest receives disagrees with the bill they pay. */
                        $amount += $priceSnapshot['unit_price'] * $units;

                        $lineName = $menu->getOriginal('name').$variantSuffix;
                        $ownerOrderText .= $lineName.($units > 1 ? " &times; {$units}" : '').'<br>';
                        $whatsappOrderLines .= "{$icon_menu_item}{$lineName} X {$units}\n";

                        /* Extras were resolved with a bare `MenuExtra::find($extra->id)`,
                           which asks nothing about WHERE that extra came from: a posted
                           id belonging to another restaurant was attached and billed, so
                           one store's order could carry another store's extra onto the
                           ticket, the KDS and the receipt. The item's own resolved set is
                           the only legitimate source, and it is also what the storefront
                           rendered — so keying off it closes the tenancy hole and, at the
                           same time, bills the pivot's `price_override` (which
                           MenuExtra::find bypassed, charging the library price instead of
                           the per-item one the diner was shown). */
                        $allowedExtras = $menu->resolved_extras->keyBy('id');
                        foreach ($item->extras ?? [] as $picked) {
                            $offered = $allowedExtras->get((int) ($picked->id ?? 0));
                            if (! $offered) {
                                continue;
                            }
                            // Snapshot the effective (price_override-applied) price so
                            // computeMoney re-totals to what was charged here.
                            OrderItemExtra::create([
                                'order_item_id' => $orderLine->getKey(),
                                'extra_id' => $offered->getKey(),
                                'price' => (float) $offered->price,
                            ]);
                            $amount += $offered->price * $units;
                            $extraName = $offered->getOriginal('title');
                            $ownerOrderText .= $extraName.'<br>';
                            $whatsappOrderLines .= "\t{$icon_menu_extra}{$extraName}\n";
                        }

                        /* #global-modifiers: option-group selections (reusable variant groups). Price is
                           re-derived server-side from the group's option_prices (never trust client price).

                           WV.V — THE UNDER-CHARGE. Until now the only thing this block did with
                           the money was add it to `$amount`, the LOCAL running total used for the
                           confirmation message, WhatsApp/SMS text and the pay-online Transaction.
                           The selections themselves were written to `order_items.order_note` as
                           TEXT and nothing else — so `Order::getTotalAttribute()`, the one price
                           authority, never saw a storefront modifier upcharge. Every cash /
                           pay-at-counter / POS-settle / charge-to-room order carrying a paid
                           modifier was settled for LESS than the storefront quoted, while a
                           pay-online order was charged MORE than the order it belongs to says it
                           is worth.

                           The fix is the snapshot every other order path already writes: the
                           selections go into `order_items.options` in the exact shape
                           `getTotalAttribute()` sums and `OrderApiService::resolveOptions()`
                           produces. Forward-only by construction — a historical line has
                           `options = NULL` and contributes 0, so no placed order re-prices.
                           The note text stays, because kitchen tickets, the KDS and the owner's
                           order screen read it. */
                        $modLines = [];
                        $modSnapshot = [];
                        $mods = $item->mods ?? [];
                        if (is_array($mods)) {
                            // The "(+2.00)" label follows the store's currency decimals so a
                            // 3-decimal store's option price isn't baked into the kitchen/receipt
                            // line rounded (0.275 OMR must not read "(+0.28)"). Resolved from the
                            // restaurant explicitly: this runs BEFORE submitOrder's own
                            // config(['settings.currency_code' => …]) override further down, so a
                            // bare currency_decimals() here would silently yield the platform's 2.
                            $priceDecimals = money_decimals(store_options($store->id, 'currency_code') ?: null);
                            $groupCounts = [];
                            $seenMods = [];
                            /* WV.V scoped these groups to the STORE, which still let a
                               payload attach a group belonging to a DIFFERENT ITEM in the
                               same store: a "Cut size +60" group offered only on the
                               steak priced itself onto a charcuterie board, and put an
                               instruction the kitchen cannot fulfil on the ticket. The
                               item's own resolved groups are the offer — and the same
                               fix the extras above got. */
                            $allowedGroups = $menu->resolved_variant_options->keyBy('id');
                            foreach ($mods as $mod) {
                                $groupId = (int) ($mod->groupId ?? 0);
                                $optIdx = isset($mod->optIndex) ? (int) $mod->optIndex : null;
                                $group = $groupId ? $allowedGroups->get($groupId) : null;
                                if ($group && ! LegacyCartLines::groupBelongsToStore($group, (int) $store->id)) {
                                    /* WV.V — the group was looked up by bare id, so a hand-edited
                                       payload could attach ANOTHER store's option group to this
                                       order. That was already a money vector (the pay-online
                                       Transaction is built from `$amount`, and an option price may
                                       legitimately be negative as long as its group sums positive),
                                       and it becomes one on the authoritative total the moment the
                                       snapshot above starts counting. Tenant-scoped now. */
                                    $group = null;
                                }
                                if (! $group || $optIdx === null) {
                                    continue;
                                }
                                // Drop duplicate selections of the same option within a group.
                                $dedupKey = $groupId.':'.$optIdx;
                                if (isset($seenMods[$dedupKey])) {
                                    continue;
                                }
                                $seenMods[$dedupKey] = true;
                                // Server-side max enforcement: refuse selections beyond the group's
                                // max so a tampered request can't add/price more than allowed.
                                $groupMax = (int) ($group->max_select ?? 0); // P6-310: 0/null = unlimited
                                $groupCounts[$groupId] = ($groupCounts[$groupId] ?? 0) + 1;
                                if ($groupMax > 0 && $groupCounts[$groupId] > $groupMax) {
                                    // BL-001: refuse with a message (same wording as LegacyCartLines::modsToOptions) instead of dropping silently.
                                    throw ValidationException::withMessages(['options' => "Choose at most {$groupMax} for “{$group->getOriginal('title')}”."]);
                                }
                                $opts = $group->getOriginal('options');
                                $opts = is_string($opts) ? json_decode($opts, true) : (array) $opts;
                                $prices = $group->option_prices ?? [];
                                if (! array_key_exists($optIdx, (array) $opts)) {
                                    continue;
                                }
                                $optLabel = $opts[$optIdx];
                                $optPrice = (float) ($prices[$optIdx] ?? 0);
                                $amount += $optPrice * $units;

                                /* Grouped by group id so the snapshot matches
                                   OrderApiService::resolveOptions() exactly — one entry per
                                   group carrying every selected option, not one per pick. */
                                if (! isset($modSnapshot[$groupId])) {
                                    $modSnapshot[$groupId] = [
                                        'group_id' => $groupId,
                                        'title' => (string) $group->getOriginal('title'),
                                        'selected' => [],
                                    ];
                                }
                                $modSnapshot[$groupId]['selected'][] = [
                                    'option' => (string) $optLabel,
                                    'price' => $optPrice,
                                ];

                                $line = $group->getOriginal('title').': '.$optLabel.($optPrice > 0 ? ' (+'.number_format($optPrice, $priceDecimals).')' : '');
                                $modLines[] = $line;
                                $ownerOrderText .= $line.'<br>';
                                $whatsappOrderLines .= "\t".$icon_menu_extra.$line."\n";
                            }
                        }
                        if (! empty($modLines)) {
                            $existingNote = $orderLine->order_note;
                            $orderLine->update([
                                'order_note' => trim(($existingNote ? $existingNote."\n" : '').implode("\n", $modLines)),
                                // WV.V: the money, in the shape the price authority sums.
                                'options' => array_values($modSnapshot),
                            ]);
                        }
                        $ownerOrderText .= '<br>';
                    }
                }

                // P4-04: every line was dropped (sold out / not on the menu / outside its window) and there is no combo: refuse the
                // order instead of leaving an EMPTY order on the kitchen screen.
                if ($placedLines === 0 && ! $resolvedCombos) {
                    throw ValidationException::withMessages(['items' => ___('Your cart is empty.')]);
                }

                /* PARITY-RUN T6: persist the combo lines. One OrderItem each, item_id NULL,
                   the whole price snapshotted into `options` — the same snapshot
                   Order::getTotalAttribute() already sums, so what is quoted here IS what
                   the order totals to. Components are expanded into the kitchen message and
                   deducted from stock individually. */
                if ($resolvedCombos) {
                    ComboService::persist($order, $resolvedCombos, false);

                    foreach ($resolvedCombos as $rc) {
                        $qty = (int) $rc['quantity'];
                        $amount += (float) $rc['unit_price'] * $qty;

                        $comboName = (string) $rc['combo']->name;
                        $ownerOrderText .= $comboName.($qty > 1 ? ' &times; '.$qty : '').'<br>';
                        $whatsappOrderLines .= $icon_menu_item.$comboName.' X '.$qty."\n";
                        foreach ($rc['components'] as $component) {
                            $ownerOrderText .= '&nbsp;&nbsp;'.$component['name'].'<br>';
                            $whatsappOrderLines .= "\t".$icon_menu_extra.$component['name']."\n";
                        }
                        $ownerOrderText .= '<br>';
                    }
                }

                $subtotal = $amount;

                /* K3: loyalty points redemption at checkout. A logged-in diner who ticked
                   "apply points" spends the program's reward_threshold points for the
                   SERVER-derived reward_discount (never a client-sent amount), folded into
                   the order discount BEFORE coupon/wallet/gift-card so those tenders draw
                   against the reduced total. The discount lands in discount_amount, so
                   Order::getTotalAttribute() stays the sole total authority (tax is still
                   on the pre-discount subtotal) — no parallel total path. Self-gates on
                   feature:loyalty per §feature-gate; when the entitlement is off the field
                   is ignored. reward_discount is a store-precision value already in the store's
                   currency; rounding through currency_decimals() keeps 3-decimal currencies
                   exact. */
                $loyaltyRedeemed = 0.0;
                /* P6-05 fix: only the PROVED owner of the order redeems — the signed-in customer or the kiosk's recognised
                   one. A guest order is linked to a customer by the phone typed at checkout (#56 capture above), which
                   proves nothing: without this guard anyone typing a member's phone spent their points. */
                $redeemOwner = auth('customer')->id() ?? $kioskCustomerId;
                if ($request->boolean('loyalty_redeem') && $redeemOwner !== null && (int) $order->customer_id === (int) $redeemOwner) {
                    try {
                        // The ONE implementation (OrderPricing::redeemLoyalty); P1-23: never on medicine lines.
                        $loyaltyRedeemed = OrderPricing::redeemLoyalty($order, (int) $store->id)['redeemed'];
                    } catch (\Throwable $e) {
                        \Log::warning('Loyalty redeem failed: '.$e->getMessage());
                    }
                }

                // Coupons & Wallets P7-1a: coupon resolve + store-credit application now
                // live in the ONE shared concern (OrderPricing) that every order surface
                // calls. It resolves the owner coupon on the food_order surface (branch
                // scope, per-customer limits, free-delivery), records coupon_id/
                // discount_amount + increments usage, and — for the logged-in diner who
                // opted in ("pay with credit") — spends store credit idempotently on the
                // fresh order. The returned discount/free_delivery feed the local total.
                $pricing = OrderPricing::applyCouponAndCredit($order, [
                    'coupon_code' => $request->filled('coupon_code') ? $request->get('coupon_code') : null,
                    'owner_id' => $store->user_id,
                    'subtotal' => $subtotal,
                    'customer_id' => auth('customer')->id(),
                    'branch_id' => $order->branch_id,
                    'use_wallet' => $request->boolean('use_wallet'),
                    'wallet_customer' => auth('customer')->user(),
                    // T2: optional gift-card tender (validated early, above, before order creation).
                    'gift_card_code' => $request->filled('gift_card_code') ? $request->get('gift_card_code') : null,
                ]);
                // K3: the loyalty redemption was already added to $order->discount_amount above;
                // reflect it in the local grand-total math ($pricing['discount'] is coupon-only).
                $discountAmount = $pricing['discount'] + $loyaltyRedeemed;
                if ($pricing['free_delivery']) {
                    // free-delivery coupon waives the delivery charge for the local total.
                    $delivery_charge = 0.0;
                } elseif ($order->type === 'delivery') {
                    // the authority's fee (Order::deliveryCharge): 0 once the items reach "free delivery over X"
                    $delivery_charge = $order->deliveryCharge();
                }

                // C5: additive — the coupon ran out between the diner's pre-flight check
                // and settlement; the order IS placed, at full price. Themes that ignore
                // this key are unaffected (same additive contract as `order_id` and
                // `token_number` below).
                $couponDeclined = ! empty($pricing['claim_denied']);

                $taxAmount = Order::computeTax(max(0.0, $subtotal - $discountAmount), store_options($store->id)); // P6-271: after the discount
                $tipAmount = $order->tip_amount;
                /* A9: the authoritative Order::computeMoney adds the hotel tray charge
                   for room_service orders, and the folio is charged that total — but
                   this LOCAL running total (which feeds the WhatsApp handoff {ORDER_TOTAL}
                   and the loyalty accrual base below) omitted it, so a cash room-service
                   order settled off the WhatsApp text under-collected and points
                   under-accrued. Add the same term the authority uses (0 for non-room). */
                $trayAmount = Order::trayCharge($order->type, $subtotal, $store->id, $order->currency_code);
                $amount = $subtotal + $delivery_charge + $taxAmount + $tipAmount + $trayAmount - $discountAmount;

                // R1c: every line, extra and coupon on this storefront order is now
                // persisted — freeze subtotal/tax/grand onto the row so list/aggregate
                // reads skip the accessor's per-item walk. Idempotent: if the coupon save
                // above already snapshotted, this recompute matches and writes nothing.
                try {
                    $order->snapshotTotals();
                } catch (\Throwable $e) {
                    report($e); // BL-049: never silent — a missing snapshot is a money bug
                }
                // P6-99b: on the VAT engine the local arithmetic above is not the charge — the order's own derivation is
                if (TaxEngine::applies($order)) {
                    $money = $order->computeMoney();
                    $taxAmount = $money['tax'];
                    $amount = $money['grand'];
                }

                // H6: commit the order write. Everything from $order->save() to here — the
                // order, its lines, combos, coupon/wallet/gift-card tender and the frozen
                // snapshot — lands together or not at all.
                if ($atomicCreate) {
                    \DB::commit();
                }
            } catch (\Throwable $e) {
                if ($atomicCreate) {
                    \DB::rollBack();
                    StoreSettings::flush(); // settings written in the rolled-back unit
                }
                // P4-04: a refused cart (not enough stock, nothing left to order) is an answer for the diner, not a failure.
                if ($e instanceof ValidationException) {
                    return response()->json(['success' => false, 'message' => implode('<br>', $e->validator->errors()->all())]);
                }
                // H6: a rolled-back order leaves NO trace — count it so an operator can
                // see the failure the merchant's diners are hitting.
                OrderApiService::recordCreateFailure((int) $store->user_id, 'storefront');
                throw $e;
            } finally {
                // Slot row is committed (or rolled back) — free the per-slot lock. The
                // duplicate-tap early return above lands here too, so it never leaks.
                optional($sfSlotLock ?? null)->release();
            }
        } // end H15 legacy pipeline branch

        if ($kioskCustomerId !== null) {
            // P6-05: the order is placed (either pipeline) — the next person at the kiosk starts unrecognised.
            app(KioskLoyalty::class)->forget((int) $store->id, $request->session());
        }

        /* GOL A1 (rev/guest-order-linking): stamp this order into the browser
           session so a later login/register/OTP in the SAME session can claim it
           (P1 proof). Guests only — a logged-in order already carries the right
           customer_id. Runs at the branch convergence point, after both pipelines
           have committed; the duplicate/replay early-returns above never reach
           here, so a resubmit is not double-stamped. Best-effort: stamping must
           never block an order. */
        if (! auth('customer')->check()) {
            try {
                GuestOrderSession::stamp($order);
            } catch (\Throwable $e) {
            }
        }

        /* H6: post-commit side effects, in the order they used to fire but now with
           the full basket persisted. Metering must not count a rolled-back order;
           the webhook / staff push / Meta Purchase now carry the REAL total instead
           of the zero-basket total they reported when they fired before the item
           loop. Each stays fire-and-forget — a side-effect failure never unwinds a
           committed order. */
        if ($deferredTranscribe !== null) {
            try {
                if (queue_worker_alive() && config('queue.default') !== 'sync') {
                    dispatch($deferredTranscribe);
                } else {
                    dispatch($deferredTranscribe)->afterResponse();
                }
            } catch (\Throwable $e) {
            }
        }

        // Abuse Guard P7: per-tenant daily order counter.
        try {
            $ownerId = (int) $store->user_id;
            $resellerId = User::whereKey($ownerId)->value('reseller_id');
            TenantUsageDaily::bump($ownerId, 'orders', 1, $resellerId ? (int) $resellerId : null);
        } catch (\Throwable $e) {
            // metering never blocks an order
        }

        // #99: Fire order.created webhook
        try {
            // F0-10: canonical order.created shape (adds type/track_token/source the
            // storefront path used to omit); keeps table_number as a storefront extra.
            WebhookService::dispatch($store->user_id, 'order.created', OrderCreatedWebhookPayload::build($order, 'storefront', [
                'table_number' => $order->table_number,
            ]));
        } catch (\Throwable $e) {
        }

        // #100: push new-order alert to the staff PWA.
        // T1: a scheduled order that hasn't fired yet must NOT alert the kitchen now —
        // the release tick (orders:release-scheduled) sends this push at fire time.
        // P6-255: a pay-online order that still owes the gateway is announced when the payment is
        // confirmed (PaymentController::paySuccess), not here — an abandoned payment never alerts the kitchen.
        if (! ($request->get('pay_via') === 'pay_online' && (float) $order->amount_due > 0.005)) {
            NewOrderAlert::send($order);
        }

        // #101: Meta Conversions API — server-side Purchase (deduped with the
        // browser Pixel via event_id 'order_{id}').
        try {
            SendMetaCapiEventJob::dispatch(
                $store->id,
                'Purchase',
                'order_'.$order->id,
                (float) $order->total,
                (string) (store_options($store->id, 'currency_code') ?: 'USD')
            );
        } catch (\Throwable $e) {
        }

        if ($order->customer_id) {
            $customer = Customer::find($order->customer_id);
            if ($customer) {
                /* M4: both aggregates move ATOMICALLY. `total_spent += $amount`
                   followed by save() was a read-modify-write against the value read
                   by find() above, so two concurrent orders by the same diner both
                   wrote (old + their own amount) and one order's money vanished from
                   the LTV — while orders_count, already an increment(), counted both.
                   Nothing reconciles this column, and it feeds VIP auto-tagging
                   (Crm\AutoTagService:45), RFM's monetary axis (Crm\RfmService:31)
                   and campaign audiences (Crm\AudienceService:70).

                   The amount is the AUTHORITATIVE order total, not the local $amount
                   arithmetic: snapshotTotals() ran above and left grand_total on the
                   row (Order::getTotalAttribute()'s own value); $amount remains the
                   fallback for the one case snapshotTotals() skips (no persisted
                   items). increment() builds a SQL expression and BYPASSES the
                   model's money cast, so the explicit round() replaces the quantisation
                   the cast's set() does — without it a decimal(10,3) column
                   accretes a phantom third decimal. currency_code is passed
                   explicitly because config('settings.currency_code') is only set for
                   this request further down (after this block). last_order_at rides
                   along in the SAME statement via the $extra argument, so the whole
                   aggregate update is one UPDATE and no save() may follow. */
                $spend = round(
                    (float) ($order->grand_total ?? $amount),
                    money_decimals(store_options($store->id, 'currency_code') ?: null)
                );
                $customer->increment('orders_count');
                // P1-05: the counter is `total_spent_minor` in the customer row's currency.
                $customer->increment('total_spent_minor', Money::minorFromMajor($spend, $customer->minorMoneyCurrency()), ['last_order_at' => now()]);
            }

            /* #57 Loyalty — earn stamps/points for this order (idempotent), via the ONE
               accrual seam (LoyaltyAccrual::earnForOrder), shared with POS + marketplace.
               A2 vesting: a pay-online order that still owes the gateway earns on payment
               confirmation (PaymentController::paySuccess), NOT here — so an abandoned
               unpaid order never earns. Cash / counter / fully-credit-covered orders (no
               gateway charge) are committed now and earn at placement. $amount is the
               authoritative local total this tail computed. */
            $awaitsGateway = $request->get('pay_via') === 'pay_online' && (float) $order->amount_due > 0.005;
            if (! $awaitsGateway) {
                LoyaltyAccrual::earnForOrder($order, (float) $amount);
            }
        }

        // Everything rendered from here on (owner mail, SMS/WhatsApp texts) speaks
        // the store's currency.
        remember_platform_currency(); // bug batch 10 · 1
        config([
            'settings.currency_sign' => store_options($store->id, 'currency_sign'),
            // Same clobber guard as the storefront render path: a store with no
            // explicit currency_pos inherits the platform default, not a suffix.
            'settings.currency_pos' => store_currency_pos($store->id),
            // USD when the store never picked one, as on the storefront — an empty
            // code left the confirmation mail's money formatting blank.
            'settings.currency_code' => (string) (store_options($store->id, 'currency_code') ?: 'USD'),
            'settings.currency_rtl_auto' => store_options($store->id, 'currency_rtl_auto', '1'),
        ]);

        $this->mailOwnerNewOrder($store, [
            'customer_name' => $guestName,
            'table_number' => $request->get('table'),
            'phone_number' => $guestPhone,
            'address' => $request->get('address'),
            'order_type' => $orderTypeLabel,
            'order' => $ownerOrderText,
            'message' => $guestMessage,
        ]);

        /* #104: Transactional order-confirmation SMS to the customer (opt-in by
           the owner via 'customer_order_sms'; transactional, so not gated by
           marketing consent). */
        if (store_options($store->id, 'customer_order_sms')) {
            $customerPhone = (string) ($guestPhone ?? $order->phone_number ?? '');
            if (trim($customerPhone) !== '') {
                $sign = store_options($store->id, 'currency_sign');
                $smsText = trim(sprintf(
                    '%s: your order #%s has been received. Total: %s%s.',
                    OutboundText::name($store->title),
                    $order->id,
                    $sign,
                    store_price_format($order->total, $store->id)
                ));
                app(MessageQueue::class)->sms($customerPhone, $smsText, $store->id, 'order', false);
            }
        }

        /* Capture explicit marketing consent when the customer ticked the WhatsApp
           opt-in on the order form (required before any broadcast to them). */
        if ($request->boolean('whatsapp_optin')) {
            $optinPhone = (string) ($guestPhone ?? $order->phone_number ?? '');
            if (trim($optinPhone) !== '') {
                WhatsAppConsent::optIn(app(WhatsApp::class)->normalizePhone($optinPhone), $store->id, 'order');
            }
        }

        /* Transactional order-confirmation over WhatsApp to the customer (owner
           opt-in via 'customer_order_whatsapp'). Business-initiated => use the
           configured approved template so it's accepted outside the 24h window. */
        if (store_options($store->id, 'customer_order_whatsapp')) {
            $customerPhone = (string) ($guestPhone ?? $order->phone_number ?? '');
            if (trim($customerPhone) !== '') {
                $sign = store_options($store->id, 'currency_sign');
                $waText = trim(sprintf('%s: your order #%s has been received. Total: %s%s.',
                    OutboundText::name($store->title), $order->id, $sign, store_price_format($order->total, $store->id)));
                app(MessageQueue::class)->whatsApp(
                    $customerPhone, $waText, $store->id, 'order', false,
                    store_options($store->id, 'whatsapp_order_template') ?: null,
                    [OutboundText::name($store->title), (string) $order->id]
                );
            }
        }

        /* Authoritative coupon-declined signal, computed from the FINALISED order:
           the diner submitted a coupon code but no coupon landed on the order
           (expired/invalid at settlement, or its cap was hit between apply and
           checkout). coupon_id is written only when a coupon actually applied — on
           both pipelines — so this supersedes the per-branch hints above (legacy
           claim_denied / the unified path's hard false) and covers every case. Lets
           the diner see they paid full price instead of it happening silently. */
        $couponDeclined = trim((string) $request->get('coupon_code')) !== '' && $order->coupon_id === null;

        /* P1-23 follow-up (pharmacy promotion block): when a coupon or a loyalty reward the diner asked for was
           refused because the order holds medicines in a market that blocks promotions, say why. */
        $promotionsNote = OrderPricing::promotionsNote($order, trim((string) $request->get('coupon_code')) !== '', $request->boolean('loyalty_redeem'));

        $result = [
            'success' => true,
            'message' => '',
            'whatsapp_url' => '',
            'coupon_declined' => $couponDeclined,
            'promotions_restricted' => $promotionsNote !== null,
            'promotions_message' => $promotionsNote,
            // Lines dropped at submit because they went sold-out / out-of-window.
            'dropped_items' => array_values(array_unique($droppedItems)),
            // The diner asked to pay with store credit but none was applied (guest,
            // or an account with no balance) — silently ignored before this.
            'wallet_ignored' => $request->boolean('use_wallet') && (float) ($order->wallet_applied ?? 0) <= 0,
        ];

        /* WhatsApp ordering (WA-C1: core-owned).
           This used to require is_plugin_enabled('quickorder'), which made the
           hand-off die silently the moment that plugin was deactivated. The gate
           is now the store's own flag — see whatsapp_ordering_enabled().

           The ten $whatsapp_number2..10 locals that used to be read here were
           dead: only $branch_whatsapp_number ever reached whatsapp_link(). */
        if (whatsapp_ordering_enabled($store->id)) {
            /* The branch that took the order owns the destination number, with
               the store-level legacy option as the fallback (resolved above). */
            /* WA-C2: template + shortcodes are core-owned now (they used to live
               in the QuickOrder plugin). Same tokens, so saved templates still work. */
            $whatsappText = $this->whatsappOrderText((int) $store->id, [
                'ORDER_ID' => $order->id,
                'ORDER_DETAILS' => $whatsappOrderLines,
                'CUSTOMER_DETAILS' => $customerDetails,
                'ORDER_TYPE' => $orderTypeLabel,
                'ORDER_TOTAL' => price_code_format($amount),
            ]);

            // Single source of truth for wa.me link building (WhatsApp channel,
            // 'link' provider). Same api.whatsapp.com/send format as before; the
            // number is now digit-normalised (safe for wa.me).
            $result['whatsapp_url'] = whatsapp_link($branch_whatsapp_number, $whatsappText);

            /* WA-S1: the owner decides what happens next. Sent alongside the URL so
               every theme behaves the same way instead of hardcoding its own. A
               theme that ignores this still works — the helper defaults to today's
               behaviour when the payload is absent. */
            $result['whatsapp_handoff'] = whatsapp_handoff_settings($store->id);

            // "Do nothing" means exactly that: no URL, so no theme can hand over.
            if (($result['whatsapp_handoff']['mode'] ?? 'auto') === 'none') {
                $result['whatsapp_url'] = '';
            }
        }

        /* T1 · H8 — post the room-service order to the guest folio (charge-to-room
           tender). Eligibility was validated in the room_service branch above; the
           order items now exist so $order->total is authoritative. chargeOrder is
           idempotent per order and stamps payment_method='charge_to_room'. A folio
           failure must not break the placed order — surface it, but keep the order. */
        if ($chargeToRoomStay) {
            // Ensure the freshly-attached items are reflected in $order->total /
            // amount_due (the relation may have been touched before they were created).
            $order->load(['items.menu', 'items.variant', 'items.itemExtras.extra']);
            // A1/A2/A10: charge the OUTSTANDING amount (not gross total), stamp the
            // tender, put a residual 'unpaid' order back on the normal flow, and — on
            // a folio failure — alert the owner + tell the guest to settle at reception.
            $result = array_merge($result, app(RoomCharge::class)->chargeOrderForTender($order, $chargeToRoomStay));
        }

        if ($request->get('pay_via') == 'pay_online' && ! $chargeToRoomStay) {

            /* Create transaction to store all the details for further actions.
             *
             * Charge what is still OWED, not the order value. Store credit, a
             * gift card and the marketplace wallet are TENDERS, not discounts:
             * OrderPricing::applyCouponAndCredit (above) has already spent them
             * and recorded them on the row, so billing the gateway the full
             * $amount took the money twice — the guest's credit was consumed
             * AND their card was charged the un-reduced total.
             *
             * Order::getAmountDueAttribute is the single authority for this
             * (total − wallet_applied − gift_card_applied − mk_wallet_applied,
             * rounded to the store's currency decimals). Read it from a fresh
             * instance so the tenders just written are visible.
             */
            $amountDue = (float) $order->fresh()->amount_due;

            $payment = $this->orderPaymentTransaction($store, $order, $amountDue, $request->ip(), [
                'whatsapp_url' => $result['whatsapp_url'],
                'customer_name' => $guestName,
                'phone' => $guestPhone,
            ]);

            // For a pay-online order the answer's `message` is where the theme sends the diner.
            $result['message'] = route('payment.index', $payment->getKey());
        }

        $result['track_url'] = route('store.orderTrack', $order->track_token);

        /* The order's own id, so a confirmation screen can show the customer a real
           reference. Without it no theme could, and several invented one instead --
           minting "#SCP-4821" or a random four-digit number that matched no row -- so
           a diner quoting it to the restaurant named an order nobody could find.
           Additive: themes that don't read it are unaffected. */
        $result['order_id'] = $order->id;

        /* PARITY-RUN T2: the customer's daily number and the board to watch for
           it. Both null for a store with the board off, so a theme can render
           "Your number: N" only when there really is one. The tracking page every
           confirmation funnels to shows it regardless (_partials/order-token). */
        // Read the column back rather than trusting this instance: a later
        // KDS status sync may have assigned the number on its own instance.
        $tokenNumber = (int) Order::where('id', $order->id)->value('token_number');
        $result['token_number'] = $tokenNumber ?: null;
        $result['board_url'] = $tokenNumber
            ? OrderBoardService::url($store, $order->branch_id ? (int) $order->branch_id : null)
            : null;

        // ZERO-TOUCH Track C: nullable prep-time estimate for the confirmation
        // screen (additive — themes may render it; the tracking page every
        // confirmation funnels to shows it regardless).
        $result['prep_eta'] = EtaEstimator::forOrder($order)?->toArray();

        return response()->json($result);
    }

    /**
     * B8-P1: the subtotal the minimum-order gate compares against, priced the same
     * way the order path will bill each line — the scheduled (or variant, which
     * replaces the base) unit price plus its paid extras — so a happy-hour basket is
     * judged on what it is actually charged, not its list price.
     *
     * @param  array<int, object>  $items
     * @param  array<int, array{unit_price: float, quantity: int}>  $resolvedCombos
     * @param  Collection<int, Menu>  $menusById
     */
    private function minimumOrderSubtotal(array $items, array $resolvedCombos, Collection $menusById, ?string $orderType, ?int $branchId): float
    {
        $subtotal = 0.0;
        foreach ($items as $item) {
            $menu = $menusById->get((int) ($item->id ?? 0));
            if (! $menu) {
                continue;
            }
            $qty = max(1, (int) ($item->quantity ?? 1));

            // Variant price replaces the base price when a valid variant is chosen.
            $chosenVariant = (isset($item->variants) && is_numeric($item->variants))
                ? MenuVariant::where('menu_id', $menu->id)->find($item->variants)
                : null;

            /* PARITY-RUN T5: resolve the unit through the SAME snapshot the order
               path persists (MenuScheduleService::snapshotFor). A schedule that
               applies REPLACES the base/variant price, exactly as the bill charges;
               extras are still added on top below. */
            $snapshot = MenuScheduleService::snapshotFor($menu, $chosenVariant, $orderType, $branchId);
            $unit = (float) $snapshot['unit_price'];

            /* Paid extras add to the unit — but only the ones this item
               actually offers. A bare find() here priced any extra id in the
               payload, so a foreign extra could carry a basket over the
               store's minimum, and (now that ordering drops those extras) the
               subtotal checked here would not be the subtotal billed. */
            $minAllowedExtras = $menu->resolved_extras->keyBy('id');
            foreach ((array) ($item->extras ?? []) as $extra) {
                $extraId = is_object($extra) ? ($extra->id ?? null) : $extra;
                $me = $extraId ? $minAllowedExtras->get((int) $extraId) : null;
                if ($me) {
                    $unit += (float) $me->price;
                }
            }
            $subtotal += $unit * $qty;
        }

        // T6: combos count toward the minimum — otherwise a store with a
        // minimum silently refused every combos-only basket.
        foreach ($resolvedCombos as $rc) {
            $subtotal += (float) $rc['unit_price'] * (int) $rc['quantity'];
        }

        return $subtotal;
    }

    /**
     * " (Large, Spicy)": one label per option group a variant points at, in the
     * variant's own order; empty when none resolve. A group the owner has since
     * deleted, or a pick the group no longer has, just adds nothing (it used to
     * 500 the checkout, mirrored from OrderItem::variantTitle).
     */
    private function variantTitleSuffix(MenuVariant $variant): string
    {
        $labels = [];
        foreach ((array) $variant->options as $groupId => $pick) {
            $group = MenuVariantOption::query()->whereKey($groupId)->first();
            if ($group === null) {
                continue;
            }
            $choices = $group->getOriginal('options');
            $choices = is_string($choices) ? json_decode($choices, true) : (array) $choices;
            if (is_array($choices) && isset($choices[$pick])) {
                $labels[] = $choices[$pick];
            }
        }

        return $labels === [] ? '' : ' ('.implode(', ', $labels).')';
    }

    /**
     * The owner's "new order" mail (settings template `new_order`), unless the
     * store switched it off (`store_submit_order_notification`, on by
     * default) or has no owner row.
     *
     * @param  array<string, mixed>  $details  the order fields StoreOrderMail merges
     */
    private function mailOwnerNewOrder(Store $store, array $details): void
    {
        $owner = $store->user;
        if ($owner === null || ! store_options($store->id, 'store_submit_order_notification', 1)) {
            return;
        }

        $owner->sendMail(new StoreOrderMail(['store_name' => $store->title] + $details));
    }

    /**
     * The store's WhatsApp order template (OrderMessage::SHORTCODES) expanded
     * with this order's values; `$values` is keyed by the tag name without braces.
     *
     * @param  array<string, mixed>  $values
     */
    private function whatsappOrderText(int $storeId, array $values): string
    {
        $tokens = [];
        foreach ($values as $tag => $value) {
            $tokens['{'.$tag.'}'] = $value;
        }

        return OrderMessage::build($storeId, $tokens);
    }

    /**
     * The pending payment row a pay-online storefront order is settled through
     * (PaymentController reads it by id). `user_id` holds the STORE id for
     * order payments, and both amounts are what is still owed.
     *
     * @param  array<string, mixed>  $details  hand-off data the payment page reads back
     */
    private function orderPaymentTransaction(Store $store, Order $order, float $due, ?string $ip, array $details): Transaction
    {
        $label = sprintf('%s (#%s)', $store->title, $order->getKey());

        return Transaction::create([
            'product_name' => $label,
            'transaction_description' => $label,
            'product_id' => $order->getKey(),
            'store_id' => $store->getKey(), // P1-04/P1-10: the store; user_id stays NULL (the diner is not a user)
            'amount' => $due,
            'base_amount' => $due,
            'currency_code' => store_options($store->getKey(), 'currency_code'),
            'transaction_method' => 'order',
            'transaction_ip' => $ip,
            'details' => $details,
        ]);
    }

    /**
     * Storefront coupon pre-flight: is this owner coupon usable on this
     * subtotal, and what would it take off? Nothing is claimed here —
     * submitOrder settles the coupon again when the order is placed.
     */
    public function applyCoupon(Request $request, Store $store)
    {
        $store = $this->checkoutStore($request, $store);

        $check = validator($request->all(), [
            'code' => 'required|string',
            'subtotal' => 'required|numeric|min:0',
        ]);
        if ($check->fails()) {
            return $this->validationFailureJson($check);
        }

        $code = $request->get('code');
        $subtotal = (float) $request->get('subtotal');

        /* P1-23 (AUP 3.1(d)): the same rule the order applies when it settles the coupon (OrderPricing) — say so now,
           not after the diner was shown a discount the placed order will not get. Needs the cart lines the themes post
           (`items`); an old bundle that posts none keeps the previous answer (settlement still refuses). */
        if ($request->has('items') && app(SalesRestrictions::class)->promotionsBlocked($store, $this->postedMenus($store, $request->input('items')))) {
            return response()->json([
                'success' => false,
                'message' => ___('Promo codes cannot be used on orders that contain medicines.'),
            ]);
        }

        $couponService = new CouponService;
        // M3: pass the signed-in diner so the per-customer cap is evaluated at the
        // storefront pre-flight; guests send null and are unaffected.
        // Bug batch 4 · 2: the discount rounds to the store currency the order will carry (KWD fils), so the pre-flight
        // shows the discount the placed order records.
        $validationResult = $couponService->validate($code, $subtotal, $store->user_id, false, auth('customer')->id(), store_currency((int) $store->id), $request->integer('branch_id') ?: null);

        if (! $validationResult['ok']) {
            return response()->json([
                'success' => false,
                'message' => $validationResult['message'],
            ]);
        }

        $coupon = $validationResult['coupon'];
        $discount = $validationResult['discount'];

        /* Bug batch 7 · 4: buy-X-get-Y is priced on the cart LINES, which a subtotal-only check cannot see (it
           previewed 0 while the placed order got the discount). The themes now post their cart (`items`, the
           submitOrder shape): price the coupon on those lines exactly as settlement does (OrderPricing → bogoDiscount
           on the order's lines: sold unit prices, store currency), capped at the subtotal like resolve(). An old
           bundle that posts no lines keeps the previous answer. */
        if ($coupon->coupon_type === 'bogo' && $request->has('items')) {
            $posted = $request->input('items');
            $posted = is_string($posted) ? json_decode($posted) : json_decode((string) json_encode($posted));
            $type = (string) ($request->input('ordering-type') ?: $request->input('type') ?: '');
            $branchId = (int) $request->input('branch_id') ?: null;
            $preview = is_array($posted)
                ? OrderApiService::previewOrder((int) $store->id, LegacyCartLines::lines($posted, $store, $type ?: null), $type ?: null, $branchId)
                : null;
            if ($preview !== null) {
                $discount = min($couponService->bogoDiscount($coupon, $preview), $subtotal);
            }
        }

        return response()->json([
            'success' => true,
            'discount' => $discount,
            'label' => $coupon->code,
            'coupon_type' => $coupon->coupon_type,
            'percentage' => $coupon->percentage,
            'message' => $validationResult['message'],
        ]);
    }

    /**
     * The store's own menu rows named by the cart lines a theme posted (JSON string or array of `{id, …}`).
     *
     * @return list<Menu>
     */
    private function postedMenus(Store $store, mixed $posted): array
    {
        $lines = is_string($posted) ? json_decode($posted, true) : (array) $posted;
        $ids = [];
        foreach ((array) $lines as $line) {
            $id = (int) (is_array($line) ? ($line['id'] ?? 0) : (is_object($line) ? ($line->id ?? 0) : 0));
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return $ids === [] ? [] : Menu::where('store_id', $store->id)->whereIn('id', array_values($ids))->get()->all();
    }

    /**
     * Menu-item analytics from the storefront: one MenuItemEvent row per
     * `view` / `add_to_cart` of an item that belongs to this store.
     *
     * @param  Store|int|string  $store  bound model, id or slug
     */
    public function recordItemEvent(Request $request, $store): JsonResponse
    {
        $store = $store instanceof Store
            ? $store
            : Store::query()->idOrSlug($store)->firstOrFail();

        $check = validator($request->all(), [
            'menu_id' => 'required|integer',
            'type' => 'required|in:view,add_to_cart',
        ]);
        if ($check->fails()) {
            return response()->json(['success' => false, 'message' => $check->errors()->first()], 422);
        }

        $item = Menu::query()
            ->whereKey($request->get('menu_id'))
            ->where('store_id', $store->getKey())
            ->first();
        if ($item === null) {
            return response()->json(['success' => false, 'message' => 'Menu item not found or does not belong to this restaurant.'], 404);
        }

        MenuItemEvent::create([
            'menu_id' => $item->getKey(),
            'store_id' => $store->getKey(),
            'type' => $request->get('type'),
        ]);

        return response()->json(['success' => true, 'message' => 'Event recorded successfully.']);
    }

    /**
     * One of the signed-in owner's stores, addressed by id or slug; any other
     * store (or none) is a 404. Shared by the menu maintenance endpoints and
     * the store clone.
     */
    private function ownedStoreByKey(int|string $key): Store
    {
        return Store::query()
            ->where('user_id', auth()->id())
            ->where(fn ($q) => $q->idOrSlug($key))
            ->firstOrFail();
    }

    /**
     * A query over one store's menu items — the live ones, or only the trashed
     * ones (D4 undo / Trash view).
     *
     * @return Builder<Menu>
     */
    private function storeMenuItems(Store $store, bool $trashed = false): Builder
    {
        return ($trashed ? Menu::onlyTrashed() : Menu::query())->where('store_id', $store->getKey());
    }

    public function menuBulkAction(Request $request, $store)
    {
        $store = $this->ownedStoreByKey($store);

        $request->validate([
            'ids' => 'required|array|max:200',
            'ids.*' => 'required|integer',
            'action' => 'required|in:activate,deactivate,move,delete,duplicate,price_set,price_adjust_percent,tag_set,tag_clear',
            'category_id' => 'nullable|integer',
            // D5: bulk price + tag operations
            'price' => 'nullable|numeric|min:0',
            'percent' => 'nullable|numeric|min:-100',
            'tag' => 'nullable|string|in:is_popular,is_vegan,is_halal,is_gluten_free,is_daily_special',
        ]);

        // Ownership guard: ids of another store's items are silently ignored.
        $items = $this->storeMenuItems($store)->whereIn('id', $request->ids)->when(in_array($request->action, ['duplicate', 'price_adjust_percent'], true), fn ($q) => $q->with(['variants', 'extras', 'attachedVariantOptions']))->get(); // P6-309: lazy loading is off

        if ($items->isEmpty()) {
            return response()->json(['success' => false, 'message' => ___('No valid items found.')]);
        }

        switch ($request->action) {
            case 'activate':
                Menu::whereIn('id', $items->pluck('id'))->update(['active' => '1']); // enum('0','1'): numeric 1 = enum INDEX = stores '0'
                break;

            case 'deactivate':
                Menu::whereIn('id', $items->pluck('id'))->update(['active' => '0']); // numeric 0 = invalid enum index (strict-mode error)
                break;

            case 'move':
                $category = MenuCategory::whereKey($request->category_id)->where('store_id', $store->getKey())->firstOrFail();
                Menu::whereIn('id', $items->pluck('id'))->update(['category_id' => $category->id]);
                break;

            case 'delete':
                /* Delete one model at a time: the image lives under
                   public_path('storage/menu/'), not on the default filesystem
                   disk, and only Menu's `deleted` hook knows whether the file
                   is shared with another item. A mass delete skips both. */
                foreach ($items as $item) {
                    $item->delete();
                }
                break;

            case 'price_set':
            case 'price_adjust_percent':
                // D5: round to the STORE's currency precision (3-decimal Gulf
                // currencies keep their fils). Rounded explicitly, then stored as
                // price_minor (P1-05).
                $decimals = money_decimals(store_options($store->id, 'currency_code') ?: null);
                if ($request->action === 'price_set') {
                    $newPrice = round((float) $request->input('price', 0), $decimals);
                    // P1-05: price is price_minor in each row's currency — write through the model.
                    foreach ($items as $item) {
                        $item->update(['price' => $newPrice]);
                    }
                } else {
                    $factor = 1 + ((float) $request->input('percent', 0) / 100);
                    foreach ($items as $item) {
                        $item->update(['price' => max(0, round((float) $item->price * $factor, $decimals))]);
                        // P6-309: variant prices move by the same percent (a variant with a null price follows the item).
                        foreach ($item->variants as $v) {
                            if ($v->price !== null) {
                                $v->update(['price' => max(0, round((float) $v->price * $factor, $decimals))]);
                            }
                        }
                    }
                }
                break;

            case 'tag_set':
            case 'tag_clear':
                // D5: bulk-toggle a Popular/dietary flag across the selection.
                $tag = (string) $request->input('tag');
                if ($tag !== '') {
                    Menu::whereIn('id', $items->pluck('id'))->update([$tag => $request->action === 'tag_set' ? 1 : 0]);
                }
                break;

            case 'duplicate':
                // P6-309: refuse past the plan's per-category item limit (same counter as a single add), then copy in one
                // transaction so a failure leaves no half-copied items; group links and recipe lines come along.
                $limit = effective_limit('menu');
                if (! Entitlements::isUnlimitedSentinel($limit)) {
                    foreach ($items->groupBy(fn ($i) => (string) $i->category_id) as $catKey => $group) {
                        if (MenuAdminService::usedFor((int) $store->id, 'menu', $catKey === '' ? null : (int) $catKey) + $group->count() > $limit) {
                            return response()->json(['success' => false, 'message' => ___('Limit exceeded, please upgrade your membership.')], 422);
                        }
                    }
                }
                DB::transaction(function () use ($items) {
                    foreach ($items as $item) {
                        $new = $item->replicate();
                        $new->name = $item->name.' ('.___('Copy').')';
                        $new->save();
                        foreach ($item->variants as $v) {
                            $nv = $v->replicate();
                            $nv->menu_id = $new->id;
                            $nv->save();
                            foreach ((array) $v->options as $o) {
                                $no = $o->replicate();
                                $no->menu_variant_id = $nv->id;
                                $no->save();
                            }
                        }
                        foreach ($item->extras as $e) {
                            $ne = $e->replicate();
                            $ne->menu_id = $new->id;
                            $ne->save();
                        }
                        // Library modifier groups attached to the item (item_modifier_group whole-group rows).
                        foreach ($item->attachedVariantOptions as $g) {
                            $new->attachedVariantOptions()->attach($g->id, [
                                'position' => $g->pivot->position, 'required' => $g->pivot->required,
                                'min_select' => $g->pivot->min_select, 'max_select' => $g->pivot->max_select, 'active' => $g->pivot->active,
                            ]);
                        }
                        foreach (RecipeItem::query()->where('menu_id', $item->id)->get() as $r) {
                            $nr = $r->replicate();
                            $nr->menu_id = $new->id;
                            $nr->save();
                        }
                    }
                });
                break;
        }

        // D6: audit trail — a bulk delete/price/tag change now leaves a log row.
        log_activity('menu.bulk.'.$request->action, $store, array_filter([
            'action' => $request->action,
            'count' => $items->count(),
            'ids' => $items->pluck('id')->all(),
            'category' => $request->action === 'move' ? (int) $request->category_id : null,
            'price' => $request->action === 'price_set' ? $request->input('price') : null,
            'percent' => $request->action === 'price_adjust_percent' ? $request->input('percent') : null,
            'tag' => in_array($request->action, ['tag_set', 'tag_clear'], true) ? $request->input('tag') : null,
        ], fn ($v) => $v !== null), $store->id);

        return response()->json(array_filter([
            'success' => true,
            'count' => $items->count(),
            // D4: a soft delete returns the ids so the toast can offer "Undo".
            'deleted_ids' => $request->action === 'delete' ? $items->pluck('id')->all() : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * D4: restore soft-deleted menu items (the "Undo" on the delete toast + the
     * Trash view's Restore button). Ownership-guarded exactly like menuBulkAction.
     */
    public function menuRestore(Request $request, $store)
    {
        $store = $this->ownedStoreByKey($store);

        $request->validate(['ids' => 'required|array|max:200', 'ids.*' => 'required|integer']);

        $count = $this->storeMenuItems($store, trashed: true)->whereIn('id', $request->ids)->restore();

        // Deleting a category hard-deletes it but soft-deletes its items; restoring
        // such an item would surface an orphan (dangling category_id, null category
        // relation) on the storefront flat load. Bring any restored orphan back
        // detached + inactive so it never reaches customers — the owner re-files and
        // re-activates it deliberately.
        if ($count) {
            $this->storeMenuItems($store)
                ->whereIn('id', $request->ids)
                ->whereNotNull('category_id')
                ->whereDoesntHave('category')
                ->update(['category_id' => null, 'active' => '0']);
        }

        log_activity('menu.restore', $store, ['count' => $count, 'ids' => array_map('intval', $request->ids)], $store->id);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'count' => $count]);
        }

        return back()->with('success', ___(':count items restored.', ['count' => $count]));
    }

    /**
     * D4: 30-day Trash view — soft-deleted items for this restaurant, each with a
     * Restore action. Ownership-guarded.
     */
    public function menuTrash($store)
    {
        $store = $this->ownedStoreByKey($store);

        return view('owner.menu.trash', [
            'store' => $store,
            'trashed' => $this->storeMenuItems($store, trashed: true)->orderByDesc('deleted_at')->get(),
        ]);
    }

    /**
     * D7: one-click un-86 — clear the sold-out flag on this restaurant's items.
     * Optional `ids[]` limits it; otherwise all sold-out items in scope.
     * Ownership-guarded exactly like menuBulkAction.
     */
    public function menuUn86(Request $request, $store)
    {
        $store = $this->ownedStoreByKey($store);

        $query = $this->storeMenuItems($store)->where('is_sold_out', 1);
        if ($request->filled('ids') && is_array($request->ids)) {
            $query->whereIn('id', array_map('intval', $request->ids));
        }
        $count = $query->update(['is_sold_out' => 0]);
        StorefrontMenuCache::bust((int) $store->id); // a bulk update fires no model events

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'count' => $count]);
        }

        return back()->with('success', ___(':count items are back in stock.', ['count' => $count]));
    }

    /**
     * #22: Guest order history lookup by phone or email
     */
    public function guestOrderLookup(Request $request, $store)
    {
        $store = Store::idOrSlug($store)->firstOrFail();

        $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $id = trim($request->get('identifier'));
        // Owner-gated (default OFF): allow looking an order up by its ORDER NUMBER
        // (the `#id` the customer sees on the confirmation / track page), not only the
        // checkout phone/email. Off by default because order ids are sequential, so
        // number lookup weakens the identifier — the store opts in. When on, a purely
        // numeric identifier is matched against the order id below, scoped to this
        // restaurant. (Enumeration is further bounded by the route's public-check
        // throttle and the store's optional order-lookup OTP.)
        $allowByNumber = (bool) store_options($store->id, 'order_lookup_by_number');
        $idNum = ($allowByNumber && ctype_digit($id)) ? (int) $id : null;

        // S4 (dark, default off): when the merchant requires it, only return
        // order data to a caller who has SMS-OTP-verified this phone this session
        // (or is signed in as the matching phone-verified customer). Otherwise a
        // store's whole customer base is phone/email-enumerable via this endpoint.
        if (filter_var(config('settings.order_lookup_require_otp'), FILTER_VALIDATE_BOOLEAN)) {
            $normalized = app(Sms::class)->normalizePhone($id);
            $looksLikePhone = $normalized !== '' && preg_match('/^\+?\d{6,}$/', $normalized);
            if (! $looksLikePhone || ! CheckoutOtpController::phoneIsVerified($normalized, (int) $store->id)) {
                return response()->json([
                    'success' => false,
                    'otp_required' => true,
                    'message' => ___('Please verify your phone to view your orders.'),
                ]);
            }
        }

        // Guest orders store the diner phone in `phone_number`; there is no
        // email column on orders, so an email (or account phone) is matched
        // through the linked customer record.
        $customerIds = Customer::where('store_id', $store->id)
            ->where(function ($q) use ($id) {
                $q->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $id))])->orWhere('phone', $id);
            })
            ->pluck('id');

        // Eager-load items so the computed `total` accessor resolves without N+1.
        $orders = Order::with(['items.menu', 'items.variant', 'items.itemExtras.extra'])
            ->where('store_id', $store->id)
            ->where(function ($q) use ($id, $customerIds, $idNum) {
                $q->where('phone_number', $id);
                if ($customerIds->isNotEmpty()) {
                    $q->orWhereIn('customer_id', $customerIds);
                }
                if ($idNum !== null) {
                    $q->orWhere('id', $idNum); // match by order number
                }
            })
            ->orderByDesc('created_at')
            ->take(10)
            ->get();

        if ($orders->isEmpty()) {
            return response()->json(['success' => false, 'message' => $allowByNumber
                ? ___('No orders found for that phone, email, or order number.')
                : ___('No orders found for that phone/email.')]);
        }

        return response()->json([
            'success' => true,
            'orders' => $orders->map(function ($o) use ($store) {
                return [
                    'id' => $o->id,
                    'token' => $o->track_token,
                    'total' => store_money_plain($o->total, $store->id), // MC-21: store sign + decimals (fils), position-aware
                    'status' => $o->status,
                    'date' => $o->created_at->format('d M Y H:i'),
                    'track_url' => $o->track_token ? route('store.orderTrack', $o->track_token) : null,
                    /* Line items, so a guest can actually reorder. This response carried
                       none, which is why guest reorder was dead in every theme: the
                       button had nothing to rebuild a cart from. Same shape the
                       signed-in account endpoint returns (BuildsReorderPayload), so a
                       theme can use one code path for both. Items whose menu row is gone,
                       inactive or sold out drop out rather than reappearing unbuyable. */
                    'items' => collect($o->items)
                        ->map(fn ($it) => $this->buildReorderItemPayload($it, $store))
                        ->filter()
                        ->values(),
                ];
            }),
        ]);
    }

    // #29: Submit a diner review for a menu item.
    // P6-284 dedupe key: a signed-in `customer` guard user keeps a stable
    // customer_id (one review per account per item). An unauthenticated diner
    // gets a long-rolling random cookie token; two different cookies share an
    // IP/UA but stay distinct, while the same cookie on a second POST is
    // blocked. We deliberately do NOT make the cookie a session cookie, so a
    // guest who clears their cookies can leave one fresh review. The IP stays
    // a throttle signal only (route throttle:public-check on web.php:697).
    public function submitMenuReview(Request $request, $slug)
    {
        $store = Store::where('slug', $slug)->firstOrFail();
        $menu = Menu::where('id', $request->menu_id)
            ->where('store_id', $store->id)
            ->firstOrFail();

        $customerId = auth('customer')->id();
        $cookieName = 'menu_review_token';
        $token = $request->cookie($cookieName);
        $setCookie = false;
        if (! $customerId && (! is_string($token) || $token === '')) {
            // 32 random bytes printed as hex — enough entropy for a per-item
            // dedupe line, never reused across items by design.
            $token = bin2hex(random_bytes(32));
            $setCookie = true;
        }

        // Hash binds the identity to THIS item: same diner can still review
        // other items on the same store, but not the same item twice.
        $identity = $customerId ? ('customer:'.$customerId) : ('cookie:'.$token);
        $hash = hash('sha256', $identity.':'.$menu->id);

        if (MenuReview::where('menu_id', $menu->id)->where('session_hash', $hash)->exists()) {
            return response()->json(['success' => false, 'message' => ___('You have already reviewed this item.')]);
        }

        $stars = (int) $request->get('stars', 5);
        $body = $request->filled('body') ? strip_tags($request->get('body')) : null;

        MenuReview::create([
            'menu_id' => $menu->id,
            'store_id' => $store->id,
            'customer_id' => $customerId ? (int) $customerId : null,
            'reviewer_name' => strip_tags((string) $request->get('reviewer_name', '')),
            'stars' => max(1, min(5, $stars)),
            // P6-284: mb_substr so Arabic / emoji bodies round-trip whole.
            'body' => $body ? mb_substr($body, 0, 500) : null,
            'status' => 'pending', // held for owner approval; rating_avg only counts approved
            'session_hash' => $hash,
        ]);

        $response = response()->json(['success' => true, 'message' => ___('Thank you! Your review will appear once approved.')]);
        if ($setCookie) {
            // 5 years — long enough that the same diner on the same browser
            // can't double-review the same item, but a cookie-cleared guest
            // can leave exactly one review per item.
            $response->withCookie(cookie($cookieName, $token, 60 * 24 * 365 * 5, '/', null, false, true));
        }

        return $response;
    }

    /**
     * MARKETPLACE T2-4: post-delivery rating prompt on the order track page.
     * Token-scoped (token → order → restaurant): the review row can only carry
     * the order's own store_id — no cross-store writes possible. Reuses
     * the storefront MenuReview flow (status=pending, moderation unchanged).
     * One review per order — dedupe key is menu_reviews.order_id.
     */
    public function submitTrackReview(Request $request, string $token)
    {
        $order = Order::where('track_token', $token)->firstOrFail();

        // Only completed/delivered orders may be rated from the track page.
        // Marketplace orders always; direct (QR) orders when the platform opens
        // ratings to them (F13, dark: settings.storefront_direct_ratings).
        $__directRatings = (bool) config('settings.storefront_direct_ratings', false);
        $__isMarketplace = ($order->source ?? null) === 'marketplace';
        if (! in_array($order->status, ['delivered', 'completed'], true)
            || (! $__isMarketplace && ! $__directRatings)) {
            return response()->json(['success' => false, 'message' => ___('This order cannot be reviewed.')], 422);
        }

        $data = $request->validate([
            'stars' => 'required|integer|min:'.(int) config('marketplace.review.min_rating', 1)
                .'|max:'.(int) config('marketplace.review.max_rating', 5),
            'body' => 'nullable|string|max:'.(int) config('marketplace.review.body_max', 2000),
        ]);

        if (MenuReview::where('order_id', $order->id)->exists()) {
            return response()->json(['success' => false, 'message' => ___('You have already reviewed this order.')], 422);
        }

        // menu_reviews.menu_id is NOT NULL — anchor the store-level review on
        // the order's first line item (documented T2-4 decision).
        $firstItem = $order->items()->orderBy('id')->first();
        if (! $firstItem || ! $firstItem->item_id) {
            return response()->json(['success' => false, 'message' => ___('This order cannot be reviewed.')], 422);
        }

        MenuReview::create([
            'menu_id' => (int) $firstItem->item_id,
            'store_id' => (int) $order->store_id,
            'order_id' => (int) $order->id,
            'customer_id' => $order->customer_id,
            'reviewer_name' => $order->customer_name ? mb_substr(strip_tags((string) $order->customer_name), 0, 80) : null,
            'stars' => (int) $data['stars'],
            'body' => isset($data['body']) && $data['body'] !== null && $data['body'] !== ''
                ? strip_tags((string) $data['body'])
                : null,
            'status' => 'pending', // moderation flow unchanged
            'session_hash' => hash('sha256', ($request->ip() ?? '').($request->userAgent() ?? '').'order:'.$order->id),
        ]);

        return response()->json(['success' => true, 'message' => ___('Thank you! Your review will appear once approved.')]);
    }

    /**
     * K1: post-payment review funnel. Bound to the order's track_token, it upserts
     * a CustomerFeedback row and returns the shipped FeedbackService funnel decision
     * verbatim — ≥4★ → {funnel:'public_review', review_url:<google_review_url>}, ≤3★
     * → {funnel:'private', review_url:null} — so the storefront reuses the exact
     * threshold. Self-gating: only when the store has configured a Google review URL
     * and the feature is allowed for the store (checked here, not via middleware,
     * because this is an anonymous surface — CONVENTIONS §feature-gate).
     */
    public function submitOrderReview(Request $request, string $token)
    {
        $order = Order::where('track_token', $token)->firstOrFail();
        $store = Store::find($order->store_id);

        $reviewUrl = (string) SafeUrl::web((string) store_options($order->store_id, 'google_review_url', ''));
        if (! $store || $reviewUrl === '' || ! FeatureAccess::storeAllows((int) $order->store_id, 'support_desk')) {
            return response()->json(['success' => false], 422);
        }

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $feedback = CustomerFeedback::firstOrCreate(
            ['order_id' => $order->id],
            [
                'store_id' => $order->store_id,
                'customer_id' => $order->customer_id,
                'token' => Str::random(48),
                'status' => 'new',
            ]
        );

        $decision = (new FeedbackService)->submit(
            $feedback,
            (int) $data['rating'],
            isset($data['comment']) && $data['comment'] !== '' ? strip_tags((string) $data['comment']) : null
        );

        return response()->json(['success' => true] + $decision);
    }

    // #62: Submit NPS feedback
    public function submitNps(Request $request, $slug)
    {
        $store = Store::where('slug', $slug)->firstOrFail();
        $score = (int) $request->get('score', 10);
        $score = max(0, min(10, $score));

        $hash = hash('sha256', ($request->ip() ?? '').($request->userAgent() ?? '').$store->id.date('Y-m-d'));
        if (NpsResponse::where('store_id', $store->id)->where('session_hash', $hash)->exists()) {
            return response()->json(['success' => true, 'message' => '']);
        }

        NpsResponse::create([
            'store_id' => $store->id,
            'score' => $score,
            'comment' => $request->filled('comment') ? substr(strip_tags($request->get('comment')), 0, 500) : null,
            'session_hash' => $hash,
        ]);

        return response()->json(['success' => true, 'message' => ___('Thank you!')]);
    }

    public function cloneRestaurant(Request $request, $store)
    {
        $source = $this->ownedStoreByKey($store);

        // A clone consumes a restaurant slot like any other create.
        $limit = effective_limit('restaurant'); // #67: includes add-on boosts
        // F5-5: sandbox stores don't consume the quota, so don't count them here either.
        if (! Entitlements::isUnlimitedSentinel($limit) && Entitlements::usage($request->user(), 'restaurant') >= $limit) { // P6-1
            return response()->json(['error' => ___('Limit exceeded, please upgrade your membership.')], 422);
        }

        // Edition limits: a same-type copy is one more store of that type.
        $edition = app(StoreLimitGuard::class)->checkNewStore($source->account_id ? (int) $source->account_id : null, $source->store_type);
        if ($edition->refused()) {
            return response()->json(['error' => $edition->message(), 'code' => 'store_limit'], 422);
        }

        // C10: the whole transactional deep-copy (menu tree + modifiers + extras +
        // pivots + options + tables/rooms) lives in StoreCloneService — this
        // controller is right up against its size-guard ceiling.
        ['clone' => $clone, 'counts' => $counts] = app(StoreCloneService::class)->clone($source);

        log_activity('store.clone', $clone, ['cloned_from' => $source->id, 'source_title' => $source->title], $clone->id);

        return response()->json([
            'success' => true,
            'id' => $clone->id,
            'title' => $clone->title,
            'counts' => $counts,
            'message' => ___(':noun cloned successfully.', ['noun' => store_noun($clone, 'title')]),
        ]);
    }

    /**
     * Cache-Control of the anonymous storefront menu with an ETag. Under an enforced nonce CSP it is `private`: the
     * visitor's own browser may revalidate (304) the page whose nonce it already holds, but no shared cache may reuse
     * nonce-bearing HTML across visitors. Report-only / no CSP: `public`, as before.
     */
    private static function menuCacheControl(): string
    {
        $ttl = (int) config('perf.menu_cache_ttl', 300);

        return (config('csp.enforce', false) || config('csp.storefront_enforce') === 'all' ? 'private' : 'public').', max-age='.$ttl;
    }
}
