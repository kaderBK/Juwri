<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Backup;

use App\Models\Audience;
use App\Models\Campaign;
use App\Models\CateringRequest;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\GiftCard;
use App\Models\ImageMenu;
use App\Models\Ingredient;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\LoyaltyProgram;
use App\Models\MenuBanner;
use App\Models\MenuCategory;
use App\Models\MenuReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PriceSchedule;
use App\Models\RecipeItem;
use App\Models\Reservation;
use App\Models\StaffMember;
use App\Models\StoreFollow;
use App\Models\StoreReview;
use App\Models\StoreTable;
use App\Models\Story;
use App\Models\WaitlistEntry;
use App\Support\StoreClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\StoreBackup;
use Juwri\Core\Services\Ops\BackupCrypto;
use Juwri\Core\Services\Ops\MediaCollector;
use Juwri\Modules\Customers\Models\CustomerFeedback;
use Juwri\Modules\Customers\Models\NpsResponse;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Hr\Models\LeaveType;
use Juwri\Modules\Hr\Models\PayrollItem;
use Juwri\Modules\Hr\Models\PayrollRun;
use Juwri\Modules\Hr\Models\StaffShift;
use Juwri\Modules\Inventory\Models\BranchStock;
use Juwri\Modules\Inventory\Models\PurchaseOrder;
use Juwri\Modules\Inventory\Models\PurchaseOrderItem;
use Juwri\Modules\Inventory\Models\StockCount;
use Juwri\Modules\Inventory\Models\StockCountItem;
use Juwri\Modules\Inventory\Models\StockMovement;
use Juwri\Modules\Inventory\Models\Supplier;
use Juwri\Modules\Inventory\Models\SupplierInvoice;
use Juwri\Modules\Media\Models\MediaAsset;
use Juwri\Modules\Menu\Models\BranchMenu;
use Juwri\Modules\Menu\Models\Combo;
use Juwri\Modules\Menu\Models\ComboSlotOption;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Services\Catalog\CatalogExport;
use Juwri\Modules\Messaging\Models\SmsOptout;
use Juwri\Modules\Messaging\Models\WhatsAppConsent;
use Juwri\Modules\Messaging\Models\WhatsAppOptout;
use Juwri\Modules\Ordering\Models\MealPlan;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Ordering\Models\OrderVoiceNote;
use Juwri\Modules\Payments\Models\Dispute;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Juwri\Modules\Pos\Models\SignageScreen;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Tables\Models\CheckPayment;
use Juwri\Modules\Tables\Models\QrDesign;
use Juwri\Modules\Tables\Models\TableCheck;
use Juwri\Modules\Tables\Models\TableMergeGroup;
use Juwri\Modules\Tables\Models\TableSession;
use Juwri\Modules\Tables\Models\WaiterCall;
use ZipArchive;

/**
 * Builds the per-restaurant export ZIP for a StoreBackup record, honouring the
 * owner's v2 choices: which datasets, which format, an order date-window, a
 * single branch, and optional AES-256 password protection.
 *
 * Security guarantees:
 *  - ALLOWLIST of datasets, never a blanket dump; secret-bearing values are
 *    never written — settings are scrubbed by the AUTHORITATIVE gateway-vault
 *    list (WI-A1) plus a name-pattern backstop, recursing into nested objects
 *    (WI-A2). Every CSV cell is neutralised against formula injection (WI-A3).
 *  - PII datasets are only ever produced when explicitly selected.
 *  - The ZIP is written to the private local disk and streamed only through an
 *    ownership-checked controller.
 *
 * Scale guarantees:
 *  - Every dataset is streamed one row at a time (WI-B1) via DatasetStreamWriter,
 *    so peak memory is O(chunk), flat regardless of row count. order_items are
 *    derived from chunks of the order cursor, never a whole-store whereIn (WI-B4).
 *  - The working directory is removed on EVERY exit path (WI-B2, try/finally).
 */
class StoreBackupService
{
    /** Canonical dataset keys the owner can pick. Order = display order. */
    public const DATASETS = [
        // Core catalog & store
        'menu', 'menu_structure', 'branches', 'tables', 'coupons', 'settings',
        'reviews', 'loyalty',
        // Inventory / purchasing
        'inventory', 'suppliers',
        // Financials
        'financials', 'loyalty_ledger',
        // Staff / HR
        'staff',
        // CRM / marketing
        'crm', 'crm_responses',
        // Customers & orders
        'customers', 'orders', 'order_money', 'gift_cards', 'check_payments',
        // Floor / service
        'reservations', 'floor_service', 'floor_sessions', 'drivers',
        // Hotel / catering
        'hotel',
        // Social graph
        'social',
        // Media rows (files handled by the `media` toggle, WI-D1)
        'media_rows', 'voice_notes',
        // Actual media FILES bundled into the ZIP (default on, WI-D1)
        'media',
    ];

    /** Total media-file byte cap per export (WI-D1 / DECISIONS D2). */
    public const MEDIA_CAP_BYTES = 500 * 1024 * 1024;

    /** Above this item count the in-memory menu.json snapshot is skipped (WI-B1). */
    public const SNAPSHOT_MAX_ITEMS = 5000;

    /** Datasets that contain personal data — only exported when picked. */
    public const PII_DATASETS = [
        'customers', 'orders', 'reservations', 'gift_cards',
        'suppliers', 'loyalty_ledger', 'staff', 'crm_responses', 'check_payments',
        'floor_sessions', 'drivers', 'hotel', 'social', 'voice_notes',
    ];

    /**
     * UI grouping for the dataset toggles (WI-F1). key => group label key. The
     * Blade iterates DATASETS and headings come from here so the checkbox list
     * can never drift from what the service exports.
     */
    public const GROUPS = [
        'menu' => 'Menu', 'menu_structure' => 'Menu', 'branches' => 'Menu',
        'tables' => 'Floor & service', 'coupons' => 'Store', 'settings' => 'Store',
        'reviews' => 'Store', 'loyalty' => 'Store',
        'inventory' => 'Inventory', 'suppliers' => 'Inventory',
        'financials' => 'Financials', 'loyalty_ledger' => 'Financials',
        'staff' => 'Staff & HR',
        'crm' => 'CRM & marketing', 'crm_responses' => 'CRM & marketing',
        'customers' => 'Customers', 'orders' => 'Orders', 'order_money' => 'Orders',
        'gift_cards' => 'Orders', 'check_payments' => 'Orders',
        'reservations' => 'Floor & service', 'floor_service' => 'Floor & service',
        'floor_sessions' => 'Floor & service', 'drivers' => 'Floor & service',
        'hotel' => 'Hotel & catering',
        'social' => 'Social',
        'media_rows' => 'Media', 'voice_notes' => 'Media', 'media' => 'Media',
    ];

    private const SECRET_PATTERNS = [
        'password', 'secret', 'token', 'api_key', 'apikey', '_key', 'private',
        'credential', 'cvv', 'card_number', 'iban', 'salt', 'hash', 'signature',
        'access_token', 'refresh_token', 'client_secret',
    ];

    public const RETENTION_DAYS = 7;

    /**
     * Registry of streamed row-files (WI-W0-1). Each entry is one output file:
     *   model  — Eloquent model class
     *   scope  — the restaurant/owner scope column on that table
     *   dataset — the owner-facing toggle key that includes this file
     *   group  — UI grouping (for the F1 checkbox groups)
     *   pii    — personal data (only exported when its dataset is picked)
     *   branch — (optional) column to filter by when a single branch is chosen
     *   order  — (optional) columns to order by
     *   scope_owner — (optional) scope column is the owner user id, not post id
     *
     * Adding a new dataset is one row here + one toggle key in DATASETS — the
     * build loop and (later) the UI are both driven from this list, so the two
     * can never drift.
     */
    public static function tableRegistry(): array
    {
        // Scope kinds:
        //   'scope' => col            direct: where(col, post->id)  [store_id]
        //   'parent' => [fk, model, pscope]  whereIn(fk, Parent::where(pscope, id)->select('id'))
        //   'branch_only' => true     whereIn(branch_id, Branch::where(store_id, id)->select('id'))
        // A direct-scope table that also carries a branch_id column is branch-
        // filtered automatically when a single branch is chosen (WI-C3).
        $rows = [
            // --- core catalog & store ------------------------------------
            'branches' => ['model' => Branch::class, 'scope' => 'store_id', 'branch' => 'id', 'dataset' => 'branches', 'pii' => false],
            'menu_categories' => ['model' => MenuCategory::class, 'scope' => 'store_id', 'dataset' => 'menu', 'pii' => false],
            'menu_items' => ['model' => Menu::class, 'scope' => 'store_id', 'order' => ['category_id', 'position'], 'dataset' => 'menu', 'pii' => false],
            'menu_extras' => ['model' => MenuExtra::class, 'scope' => 'store_id', 'dataset' => 'menu', 'pii' => false],
            'tables' => ['model' => StoreTable::class, 'scope' => 'store_id', 'dataset' => 'tables', 'pii' => false],
            'menu_reviews' => ['model' => MenuReview::class, 'scope' => 'store_id', 'dataset' => 'reviews', 'pii' => false],
            'store_reviews' => ['model' => StoreReview::class, 'scope' => 'store_id', 'dataset' => 'reviews', 'pii' => false],
            'loyalty_programs' => ['model' => LoyaltyProgram::class, 'scope' => 'store_id', 'dataset' => 'loyalty', 'pii' => false],
            'customers' => ['model' => Customer::class, 'scope' => 'store_id', 'dataset' => 'customers', 'pii' => true],
            'loyalty_accounts' => ['model' => LoyaltyAccount::class, 'scope' => 'store_id', 'dataset' => 'customers', 'pii' => true],
            'reservations' => ['model' => Reservation::class, 'scope' => 'store_id', 'dataset' => 'reservations', 'pii' => true],
            'gift_cards' => ['model' => GiftCard::class, 'scope' => 'store_id', 'dataset' => 'gift_cards', 'pii' => true],

            // --- inventory / purchasing ----------------------------------
            'ingredients' => ['model' => Ingredient::class, 'scope' => 'store_id', 'dataset' => 'inventory', 'pii' => false],
            'recipe_items' => ['model' => RecipeItem::class, 'parent' => ['menu_id', Menu::class, 'store_id'], 'dataset' => 'inventory', 'pii' => false],
            'purchase_orders' => ['model' => PurchaseOrder::class, 'scope' => 'store_id', 'dataset' => 'inventory', 'pii' => false],
            'purchase_order_items' => ['model' => PurchaseOrderItem::class, 'parent' => ['purchase_order_id', PurchaseOrder::class, 'store_id'], 'dataset' => 'inventory', 'pii' => false],
            'stock_counts' => ['model' => StockCount::class, 'scope' => 'store_id', 'dataset' => 'inventory', 'pii' => false],
            'stock_count_items' => ['model' => StockCountItem::class, 'parent' => ['stock_count_id', StockCount::class, 'store_id'], 'dataset' => 'inventory', 'pii' => false],
            'stock_movements' => ['model' => StockMovement::class, 'scope' => 'store_id', 'dataset' => 'inventory', 'pii' => false],
            'branch_stocks' => ['model' => BranchStock::class, 'branch_only' => true, 'dataset' => 'inventory', 'pii' => false],
            'supplier_invoices' => ['model' => SupplierInvoice::class, 'scope' => 'store_id', 'dataset' => 'inventory', 'pii' => false],
            'suppliers' => ['model' => Supplier::class, 'scope' => 'store_id', 'dataset' => 'suppliers', 'pii' => true],

            // --- financials ----------------------------------------------
            'fiscal_documents' => ['model' => FiscalDocument::class, 'scope' => 'store_id', 'dataset' => 'financials', 'pii' => false],
            'disputes' => ['model' => Dispute::class, 'scope' => 'store_id', 'dataset' => 'financials', 'pii' => false],
            'loyalty_ledger' => ['model' => LoyaltyLedger::class, 'parent' => ['loyalty_account_id', LoyaltyAccount::class, 'store_id'], 'dataset' => 'loyalty_ledger', 'pii' => true],

            // --- staff / HR ----------------------------------------------
            'restaurant_staff' => ['model' => StaffMember::class, 'scope' => 'store_id', 'dataset' => 'staff', 'pii' => true],
            'staff_shifts' => ['model' => StaffShift::class, 'scope' => 'store_id', 'dataset' => 'staff', 'pii' => true],
            'payroll_runs' => ['model' => PayrollRun::class, 'scope' => 'store_id', 'dataset' => 'staff', 'pii' => true],
            'payroll_items' => ['model' => PayrollItem::class, 'parent' => ['payroll_run_id', PayrollRun::class, 'store_id'], 'dataset' => 'staff', 'pii' => true],
            'leave_types' => ['model' => LeaveType::class, 'scope' => 'store_id', 'dataset' => 'staff', 'pii' => false],

            // --- menu structure ------------------------------------------
            'combos' => ['model' => Combo::class, 'scope' => 'store_id', 'dataset' => 'menu_structure', 'pii' => false],
            'combo_slot_options' => ['model' => ComboSlotOption::class, 'scope' => 'store_id', 'dataset' => 'menu_structure', 'pii' => false],
            'menu_variants' => ['model' => MenuVariant::class, 'parent' => ['menu_id', Menu::class, 'store_id'], 'dataset' => 'menu_structure', 'pii' => false],
            'menu_variant_options' => ['model' => MenuVariantOption::class, 'parent' => ['menu_id', Menu::class, 'store_id'], 'dataset' => 'menu_structure', 'pii' => false],
            'menu_banners' => ['model' => MenuBanner::class, 'scope' => 'store_id', 'dataset' => 'menu_structure', 'pii' => false],
            'price_schedules' => ['model' => PriceSchedule::class, 'scope' => 'store_id', 'dataset' => 'menu_structure', 'pii' => false],
            'branch_menu' => ['model' => BranchMenu::class, 'branch_only' => true, 'dataset' => 'menu_structure', 'pii' => false],

            // --- CRM / marketing -----------------------------------------
            'campaigns' => ['model' => Campaign::class, 'scope' => 'store_id', 'dataset' => 'crm', 'pii' => false],
            'audiences' => ['model' => Audience::class, 'scope' => 'store_id', 'dataset' => 'crm', 'pii' => false],
            'nps_responses' => ['model' => NpsResponse::class, 'scope' => 'store_id', 'dataset' => 'crm_responses', 'pii' => true],
            'customer_feedback' => ['model' => CustomerFeedback::class, 'scope' => 'store_id', 'dataset' => 'crm_responses', 'pii' => true],
            'waitlist_entries' => ['model' => WaitlistEntry::class, 'scope' => 'store_id', 'dataset' => 'crm_responses', 'pii' => true],
            'sms_optouts' => ['model' => SmsOptout::class, 'scope' => 'store_id', 'dataset' => 'crm_responses', 'pii' => true],
            'whatsapp_consent' => ['model' => WhatsAppConsent::class, 'scope' => 'store_id', 'dataset' => 'crm_responses', 'pii' => true],
            'whatsapp_optouts' => ['model' => WhatsAppOptout::class, 'scope' => 'store_id', 'dataset' => 'crm_responses', 'pii' => true],

            // --- order money trail ---------------------------------------
            'order_adjustments' => ['model' => OrderAdjustment::class, 'parent' => ['order_id', Order::class, 'store_id'], 'dataset' => 'order_money', 'pii' => false],
            'check_payments' => ['model' => CheckPayment::class, 'parent' => ['table_check_id', TableCheck::class, 'store_id'], 'dataset' => 'check_payments', 'pii' => true],

            // --- floor / service -----------------------------------------
            'waiter_calls' => ['model' => WaiterCall::class, 'scope' => 'store_id', 'dataset' => 'floor_service', 'pii' => false],
            'table_merge_groups' => ['model' => TableMergeGroup::class, 'scope' => 'store_id', 'dataset' => 'floor_service', 'pii' => false],
            'signage_screens' => ['model' => SignageScreen::class, 'scope' => 'store_id', 'dataset' => 'floor_service', 'pii' => false],
            'qr_designs' => ['model' => QrDesign::class, 'scope' => 'store_id', 'dataset' => 'floor_service', 'pii' => false],
            'stories' => ['model' => Story::class, 'scope' => 'store_id', 'dataset' => 'floor_service', 'pii' => false],
            'table_sessions' => ['model' => TableSession::class, 'scope' => 'store_id', 'dataset' => 'floor_sessions', 'pii' => true],
            'drivers' => ['model' => Driver::class, 'scope' => 'store_id', 'dataset' => 'drivers', 'pii' => true],

            // --- hotel / catering ----------------------------------------
            'meal_plans' => ['model' => MealPlan::class, 'scope' => 'store_id', 'dataset' => 'hotel', 'pii' => true],
            'catering_requests' => ['model' => CateringRequest::class, 'scope' => 'store_id', 'dataset' => 'hotel', 'pii' => true],

            // --- social graph --------------------------------------------
            'restaurant_follows' => ['model' => StoreFollow::class, 'scope' => 'store_id', 'dataset' => 'social', 'pii' => true],

            // --- media rows (files handled in WI-D1) ---------------------
            'media_assets' => ['model' => MediaAsset::class, 'scope' => 'store_id', 'dataset' => 'media_rows', 'pii' => false],
            'image_menus' => ['model' => ImageMenu::class, 'scope' => 'store_id', 'dataset' => 'media_rows', 'pii' => false],
            'order_voice_notes' => ['model' => OrderVoiceNote::class, 'scope' => 'store_id', 'dataset' => 'voice_notes', 'pii' => true],
        ];

        return self::withExtensions($rows);
    }

    /**
     * P1-13: tables owned by modules (Accounting GL, Hotel stays; ee: Marketplace shopper graph) are registered by
     * those modules' providers ({@see extend()}) at their old position, so the registry — and every backup/purge/
     * archive driven by it — is unchanged; without the Enterprise package its tables are simply not part of a store backup.
     *
     * @var array<string, array{after: string, rows: array<string, array<string, mixed>>}>
     */
    private static array $extensions = [];

    /**
     * Add dataset rows after an existing key (same row shape as {@see tableRegistry()}). Idempotent per module.
     *
     * @param  array<string, array<string, mixed>>  $rows
     */
    public static function extend(string $module, string $after, array $rows): void
    {
        self::$extensions[$module] = ['after' => $after, 'rows' => $rows];
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private static function withExtensions(array $rows): array
    {
        foreach (self::$extensions as $ext) {
            $pos = array_search($ext['after'], array_keys($rows), true);
            $pos = $pos === false ? count($rows) : $pos + 1;
            $rows = array_slice($rows, 0, $pos, true) + $ext['rows'] + array_slice($rows, $pos, null, true);
        }

        return $rows;
    }

    /** Datasets that are deliberately EXCLUDED (secret/config/ephemeral/global). */
    public const EXCLUDED = [
        'Credential' => 'secret', 'AggregatorConnection' => 'secret', 'PosBridgeConnection' => 'secret',
        'FiscalCertificate' => 'secret', 'FiscalDevice' => 'config', 'PrinterProfile' => 'config',
        'PhoneLine' => 'config', 'WhatsAppRoute' => 'config', 'WhatsAppTemplate' => 'config',
        'WhatsAppWindow' => 'ephemeral', 'PushSubscription' => 'ephemeral', 'DailyToken' => 'ephemeral',
        'ActivityLog' => 'ephemeral', 'MenuItemEvent' => 'ephemeral', 'AggregatorSyncLog' => 'ephemeral',
        'TranslationMemory' => 'config', 'Cart' => 'ephemeral',
        'Allergy' => 'global catalog (not store-scoped)',
    ];

    public function build(StoreBackup $backup): void
    {
        $store = Store::where('id', $backup->store_id)
            ->where('user_id', $backup->user_id)
            ->firstOrFail();

        $selected = $this->resolveDatasets($backup->datasets);
        $format = in_array($backup->format, ['both', 'json', 'csv'], true) ? $backup->format : 'both';
        $branchId = $backup->branch_id ? (int) $backup->branch_id : null;

        $work = storage_path('app/tmp/store-backup-'.$backup->token);
        @mkdir($work, 0755, true);

        $writeJson = ($format === 'both' || $format === 'json');
        $writeCsv = ($format === 'both' || $format === 'csv');

        // WI-A4: fail CLOSED. If a password was requested but this server cannot
        // AES-encrypt, refuse to ship a readable archive the owner believes is
        // protected — mark the build failed with a clear message instead.
        $password = $this->decryptPassword($backup);
        $wantsEncryption = $password !== null;
        if ($wantsEncryption && ! $this->serverCanEncrypt()) {
            $this->rrmdir($work);
            $backup->update([
                'status' => 'failed',
                'encrypted' => false,
                'password_enc' => null,
                'error' => 'This server cannot password-protect the archive, so the backup was not created. Please try again without a password.',
            ]);

            return;
        }

        // Everything below runs inside try/finally so the working directory —
        // which holds partial customer/order dumps — is ALWAYS removed, even if
        // a query or encode throws mid-build (WI-B2).
        try {
            $counts = [];

            // --- always: restaurant profile --------------------------------
            $counts['store'] = $this->streamOne($work, 'restaurant', $this->scrub($store->toArray()), $writeJson, $writeCsv);

            // --- settings blob (single row) ---------------------------------
            if (in_array('settings', $selected, true)) {
                $counts['settings'] = $this->streamOne(
                    $work, 'settings', $this->scrubSettings((array) store_options($store->id)), $writeJson, $writeCsv
                );

                // Customizer v2 (WI-V2): the theme customizer's settings are an
                // owner dataset the backup promised to cover. They ride with the
                // `settings` toggle as their own table so a restore brings a
                // store's look back with the rest of its configuration.
                $themeRows = ThemeSetting::where('store_id', $store->id)
                    ->get(['template', 'settings', 'draft'])->map(static fn ($r) => [
                        'template' => $r->template,
                        'settings' => $r->settings,
                        'draft' => $r->draft,
                    ])->all();
                if ($themeRows !== []) {
                    $w = new DatasetStreamWriter($work, 'theme_settings', $writeJson, $writeCsv);
                    foreach ($themeRows as $row) {
                        $w->write($row);
                    }
                    $counts['theme_settings'] = $w->close();
                }
            }

            // --- menu snapshot (JSON convenience) ---------------------------
            // Bounded: CatalogExport materializes the whole catalog in memory, so
            // for a very large menu we SKIP the convenience snapshot (an OOM here
            // is fatal and would stick the job) — the streamed menu_* row tables
            // below are the authoritative source of truth (WI-B1).
            if (in_array('menu', $selected, true) && $writeJson
                && Menu::where('store_id', $store->id)->count() <= self::SNAPSHOT_MAX_ITEMS) {
                try {
                    file_put_contents($work.'/menu.json', json_encode(
                        CatalogExport::forRestaurant((int) $store->id, $branchId, true),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ));
                } catch (\Throwable $e) {
                    // snapshot is a convenience; the row tables below are the source of truth
                }
            }

            // Progress accounting (WI-F1): each selected registry file + the
            // specials (menu snapshot already done, orders, media) is one step.
            $steps = 0;
            foreach (self::tableRegistry() as $spec) {
                if (in_array($spec['dataset'], $selected, true)) {
                    $steps++;
                }
            }
            $steps += (in_array('orders', $selected, true) ? 1 : 0)
                + (in_array('media', $selected, true) ? 1 : 0)
                + (in_array('coupons', $selected, true) ? 1 : 0);
            $steps = max(1, $steps);
            $done = 0;
            $tick = function (string $label) use ($backup, &$done, $steps) {
                $done++;
                $backup->update([
                    'progress' => (int) min(99, floor($done / $steps * 100)),
                    'current_step' => $label,
                ]);
            };

            // --- registry-driven streamed datasets --------------------------
            $branchScoped = [];
            foreach (self::tableRegistry() as $file => $spec) {
                if (! in_array($spec['dataset'], $selected, true)) {
                    continue;
                }
                $branched = false;
                $counts[$file] = $this->streamTable($work, $file, $spec, $store, $branchId, $writeJson, $writeCsv, $branched);
                if ($branched) {
                    $branchScoped[] = $file;
                }
                $tick($file);
            }

            // --- coupons: scope to THIS store, never leak another store's ----
            // (WI-C2). coupons has no store_id — it is owner-keyed with an
            // optional branch_id. Include coupons bound to one of this store's
            // branches, plus owner-wide (no branch) coupons TAGGED as such; a
            // coupon bound to another store's branch is excluded.
            if (in_array('coupons', $selected, true)) {
                $branchIds = Branch::where('store_id', $store->id)->pluck('id')->all();
                $cq = Coupon::where('user_id', $store->user_id)
                    ->where(function ($q) use ($branchIds) {
                        $q->whereNull('branch_id');
                        if ($branchIds) {
                            $q->orWhereIn('branch_id', $branchIds);
                        }
                    });
                $counts['coupons'] = $this->streamQuery(
                    $work, 'coupons', $cq, $writeJson, $writeCsv,
                    fn (array $row) => $row + ['_scope' => empty($row['branch_id']) ? 'owner-wide' : 'store']
                );
                $tick('coupons');
            }

            // --- orders + their items (chunked; no whole-store whereIn) ------
            if (in_array('orders', $selected, true)) {
                $oq = Order::where('store_id', $store->id)->orderBy('id');
                if ($branchId) {
                    $oq->where('branch_id', $branchId);
                }
                StoreClock::whereLocalDateBetween($oq, 'created_at', $store->id, $backup->orders_from?->toDateString(), $backup->orders_to?->toDateString());
                [$counts['orders'], $counts['order_items'], $counts['order_item_extras']] =
                    $this->streamOrdersWithItems($work, $oq, $writeJson, $writeCsv);
                $tick('orders');
            }

            if ($branchId) {
                $branchScoped[] = 'orders';
            }

            // --- media files (WI-D1): bundle real files, capped, deduped -----
            $mediaInfo = null;
            if (in_array('media', $selected, true)) {
                $mediaInfo = $this->collectMedia($work, $store);
                $tick('media');
            }

            // Test/extension seam: a hook after all datasets are streamed but
            // before the archive is packed. A throw here must still leave no tmp
            // dir behind (WI-B2 finally). No-op in production.
            $this->afterDatasets($work);

            // --- manifest (always JSON) — WI-C3/C4 honest declaration -------
            $manifest = $this->buildManifest($store, $backup, $selected, $format, $branchId, $counts, $branchScoped, $mediaInfo ?? null);
            file_put_contents($work.'/manifest.json', json_encode(
                $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ));

            // --- zip (optionally AES-256 encrypted) -------------------------
            $relPath = 'store-backups/'.$store->id.'/'.$backup->token.'.zip';
            $absPath = storage_path('app/'.$relPath);
            @mkdir(dirname($absPath), 0755, true);

            $canEncrypt = $wantsEncryption; // already gated above (WI-A4)

            $zip = new ZipArchive;
            if ($zip->open($absPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create ZIP archive.');
            }
            if ($canEncrypt) {
                $zip->setPassword($password);
            }
            // Add every file, recursing into media/<type>/… with relative paths.
            $base = rtrim($work, '/').'/';
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($work, \FilesystemIterator::SKIP_DOTS)
            );
            $encOk = true;
            foreach ($it as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $entry = str_replace($base, '', $file->getPathname());
                $zip->addFile($file->getPathname(), $entry);
                if ($canEncrypt && ! $zip->setEncryptionName($entry, ZipArchive::EM_AES_256)) {
                    $encOk = false; // this server declares AES but couldn't apply it
                }
            }
            $closeOk = $zip->close();

            // WI-A4 (runtime): the capability probe is compile-time; verify the
            // archive actually closed and — when a password was requested — that
            // every entry was encrypted. If not, do NOT ship a readable archive
            // the owner believes is protected; fail so the row is marked failed.
            if (! $closeOk || ($canEncrypt && ! $encOk)) {
                @unlink($absPath);
                throw new \RuntimeException($canEncrypt
                    ? 'The archive could not be encrypted on this server.'
                    : 'The archive could not be finalised.');
            }

            // P6-96b: encrypt-at-rest while the archive waits on the server for its owner (JWBK, the platform
            // backup key; no key → stored as before). The download decrypts and authenticates it first.
            try {
                BackupCrypto::sealInPlace($absPath);
            } catch (\Throwable $e) {
                @unlink($absPath);
                throw $e;
            }

            $backup->update([
                'status' => 'ready',
                'progress' => 100,
                'current_step' => null,
                'path' => $relPath,
                'size_bytes' => is_file($absPath) ? filesize($absPath) : null,
                'encrypted' => $canEncrypt,
                'password_enc' => null, // clear the transient password the instant the archive exists
                'expires_at' => now()->addDays(self::RETENTION_DAYS),
                'error' => null,
            ]);
        } finally {
            $this->rrmdir($work);
        }
    }

    /**
     * Resolve the effective dataset list. null/empty → all non-PII (the safe
     * default). An explicit list is intersected with the known keys, so a
     * client can never smuggle in an unknown table name.
     */
    private function resolveDatasets($datasets): array
    {
        if (! is_array($datasets) || $datasets === []) {
            return array_values(array_diff(self::DATASETS, self::PII_DATASETS));
        }

        return array_values(array_intersect(self::DATASETS, $datasets));
    }

    /**
     * WI-C3/C4: an honest manifest. Declares exactly which datasets are in the
     * archive (with their files, PII flag, and whether they were branch-scoped),
     * which are deliberately excluded and why, the media/branch scope, and a
     * plain-language disclaimer that this is a curated export.
     */
    private function buildManifest(Store $store, StoreBackup $backup, array $selected, string $format, ?int $branchId, array $counts, array $branchScoped, ?array $mediaInfo): array
    {
        // Map each toggle to the files it produced.
        $datasetFiles = [];
        foreach (self::tableRegistry() as $file => $spec) {
            $datasetFiles[$spec['dataset']][] = $file;
        }
        // Specials not in the registry.
        $datasetFiles['menu'] = array_merge(['menu (snapshot)'], $datasetFiles['menu'] ?? []);
        $datasetFiles['settings'][] = 'settings';
        $datasetFiles['coupons'][] = 'coupons';
        $datasetFiles['orders'] = ['orders', 'order_items', 'order_item_extras'];

        $branchScopedSet = array_flip($branchScoped);
        $included = [];
        foreach ($selected as $key) {
            $files = $datasetFiles[$key] ?? [$key];
            $included[$key] = [
                'group' => self::GROUPS[$key] ?? 'Other',
                'pii' => in_array($key, self::PII_DATASETS, true),
                'files' => array_values($files),
                'branch_scoped' => (bool) array_intersect($files, array_keys($branchScopedSet))
                    || ($key === 'orders' && $branchId) || ($key === 'branches' && $branchId),
            ];
        }

        $branchName = $branchId ? Branch::where('id', $branchId)->value('name') : null;

        return [
            'schema' => 3,
            'store_id' => (int) $store->id,
            'store_name' => $store->name ?? $store->title ?? null,
            'store_slug' => $store->slug ?? null,
            'generated_at' => now()->toIso8601String(),
            'format' => $format,
            'encrypted' => (bool) $backup->encrypted,
            'includes_pii' => (bool) array_intersect($selected, self::PII_DATASETS),
            'datasets' => $selected, // back-compat flat list (schema 2 readers)
            'datasets_included' => $included,
            'datasets_excluded' => self::EXCLUDED,
            'media_included' => $mediaInfo ?? false,
            'branch_scope' => $branchId
                ? [
                    'id' => $branchId,
                    'name' => $branchName,
                    'note' => 'Datasets carrying a branch are filtered to this branch (rows with no branch — meaning "all branches" — are also included); store-level datasets (customers, coupons, settings, catalog) are included whole and marked branch_scoped=false.',
                ]
                : 'all',
            'orders_window' => [
                'from' => optional($backup->orders_from)->toDateString(),
                'to' => optional($backup->orders_to)->toDateString(),
            ],
            'counts' => $counts,
            'secret_note' => 'Secret fields (payment/gateway API keys, tokens, certificates) are intentionally excluded.',
            'disclaimer' => 'This is a curated portable export of your store data, not a byte-for-byte database dump. Secret credentials and ephemeral/system rows (see datasets_excluded) are omitted by design.',
        ];
    }

    private function decryptPassword(StoreBackup $backup): ?string
    {
        if (! $backup->encrypted || ! $backup->password_enc) {
            return null;
        }
        try {
            $pw = Crypt::decryptString($backup->password_enc);

            return $pw !== '' ? $pw : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Write a single already-scrubbed row as its own dataset file. */
    private function streamOne(string $work, string $file, array $row, bool $json, bool $csv): int
    {
        $w = new DatasetStreamWriter($work, $file, $json, $csv);
        $w->write($row);

        return $w->close();
    }

    /**
     * C3: the full-store-scoped query for one registry spec, with NO branch
     * filter. The single source of truth for how the three scope kinds resolve —
     * StorePurgeService (delete) and AdminStoreArchive (export/restore) both drive
     * off this so a purge, an archive and an export can never disagree about what
     * "this store's rows" are. Mirrors streamTable()'s resolution, branch logic
     * omitted deliberately (a full backup/restore is never branch-scoped).
     *
     * @param  array<string, mixed>  $spec
     * @return Builder<Model>
     */
    public static function registryScopedQuery(array $spec, Store $store)
    {
        $model = $spec['model'];
        $q = $model::query();

        if (! empty($spec['branch_only'])) {
            $q->whereIn('branch_id', Branch::where('store_id', $store->id)->select('id'));
        } elseif (! empty($spec['parent'])) {
            [$fk, $parentModel, $pscope] = $spec['parent'];
            $q->whereIn($fk, $parentModel::where($pscope, $store->id)->select('id'));
        } else {
            $q->where($spec['scope'], $store->id);
        }

        return $q;
    }

    /**
     * Stream one registry table to its file(s), scrubbing every row. Resolves
     * the three scope kinds (direct column / parent subquery / branch-only) and,
     * for a direct-scope table that also carries a branch_id column, filters by
     * the chosen branch (WI-C3) — setting $branched so the manifest can declare
     * exactly which datasets were branch-scoped.
     */
    private function streamTable(string $work, string $file, array $spec, Store $store, ?int $branchId, bool $json, bool $csv, bool &$branched = false): int
    {
        $model = $spec['model'];
        $q = $model::query();

        if (! empty($spec['branch_only'])) {
            // Branch-scoped table (no store_id): scope by this store's
            // branch ids via a SQL subquery (memory-safe).
            $q->whereIn('branch_id', Branch::where('store_id', $store->id)->select('id'));
            if ($branchId) {
                $q->where('branch_id', $branchId);
                $branched = true;
            }
        } elseif (! empty($spec['parent'])) {
            // Parent-scoped table: whereIn(fk, Parent::where(pscope, id)->select id)
            [$fk, $parentModel, $pscope] = $spec['parent'];
            $q->whereIn($fk, $parentModel::where($pscope, $store->id)->select('id'));
        } else {
            $q->where($spec['scope'], $store->id);
            // Honest branch filter: a store-level table that carries a branch_id
            // column is narrowed to the chosen branch. A NULL branch_id means
            // "applies to every branch" (combos, expenses, GL, legacy tables),
            // so those rows are KEPT for a single-branch export too (WI-C3) —
            // dropping them would make the export incomplete for that branch.
            if ($branchId) {
                $col = $spec['branch'] ?? 'branch_id';
                if (Schema::hasColumn((new $model)->getTable(), $col)) {
                    if ($col === 'branch_id') {
                        $q->where(fn ($w) => $w->where($col, $branchId)->orWhereNull($col));
                    } else {
                        $q->where($col, $branchId); // the branches table's own id
                    }
                    $branched = true;
                }
            }
        }

        foreach ((array) ($spec['order'] ?? []) as $col) {
            $q->orderBy($col);
        }

        return $this->streamQuery($work, $file, $q, $json, $csv);
    }

    /**
     * Stream any query to a dataset file via a bounded-memory cursor. An optional
     * $transform mutates each scrubbed row before it is written (e.g. tagging a
     * coupon's provenance, WI-C2).
     */
    private function streamQuery(string $work, string $file, $query, bool $json, bool $csv, ?callable $transform = null): int
    {
        $w = new DatasetStreamWriter($work, $file, $json, $csv);
        foreach ($query->cursor() as $model) {
            $row = $this->scrub($model->toArray());
            if ($transform) {
                $row = $transform($row);
            }
            $w->write($row);
        }

        return $w->close();
    }

    /**
     * Stream orders and derive their items from the SAME cursor in id-chunks —
     * never a whole-store whereIn() over every order id (WI-B1/B4). Both files
     * are written incrementally; peak memory is O(chunk).
     *
     * @return array{0:int,1:int,2:int} [orders count, order_items count, order_item_extras count]
     */
    private function streamOrdersWithItems(string $work, $ordersQuery, bool $json, bool $csv): array
    {
        $ordersW = new DatasetStreamWriter($work, 'orders', $json, $csv);
        $itemsW = new DatasetStreamWriter($work, 'order_items', $json, $csv);
        $extrasW = new DatasetStreamWriter($work, 'order_item_extras', $json, $csv);

        $ordersQuery->chunkById(1000, function ($chunk) use ($ordersW, $itemsW, $extrasW) {
            foreach ($chunk as $order) {
                $ordersW->write($this->scrubOrderRow($this->scrub($order->toArray())));
            }
            // C3: order_item_extras is a two-hop child (extra -> item -> order) the
            // registry's single-hop 'parent' kind cannot express, so it rides the
            // same order chunk here. $itemIds is bounded by the 1000-order chunk's
            // items, never the whole store — memory stays O(chunk) (WI-B1/B4).
            $itemIds = [];
            foreach (OrderItem::whereIn('order_id', $chunk->pluck('id'))->cursor() as $item) {
                $itemsW->write($this->scrub($item->toArray()));
                $itemIds[] = $item->id;
            }
            foreach (array_chunk($itemIds, 1000) as $slice) {
                foreach (OrderItemExtra::whereIn('order_item_id', $slice)->cursor() as $x) {
                    $extrasW->write($this->scrub($x->toArray()));
                }
            }
        });

        return [$ordersW->close(), $itemsW->close(), $extrasW->close()];
    }

    /**
     * Scrub a model/settings row of secret-bearing values. Recurses into nested
     * arrays AND objects (WI-A2) — a stdClass from a JSON cast or from
     * array_to_object() no longer hides a secret at depth. Optionally drops keys
     * that appear in an authoritative name set (the gateway vault list, WI-A1).
     *
     * @param  array<string,int>  $vaultFlip  option-name => 1 to drop exactly
     */
    private function scrub(array $row, array $vaultFlip = []): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            if ($vaultFlip !== [] && isset($vaultFlip[$key])) {
                continue; // authoritative drop (WI-A1)
            }
            $lk = strtolower((string) $key);
            foreach (self::SECRET_PATTERNS as $pat) {
                if (str_contains($lk, $pat)) {
                    continue 2; // name-pattern backstop
                }
            }
            if (is_array($value)) {
                $out[$key] = $this->scrub($value, $vaultFlip);
            } elseif (is_object($value)) {
                $out[$key] = $this->scrub((array) $value, $vaultFlip);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Scrub the settings blob by the AUTHORITATIVE gateway-vault list (WI-A1) in
     * addition to the name-pattern backstop, recursing into nested objects
     * (WI-A2). `authkey`/`app_id`-style names that match no pattern are dropped
     * because the vault says they are credentials.
     */
    private function scrubSettings(array $settings): array
    {
        return $this->scrub($settings, array_flip(GatewaySecretVault::secretKeys()));
    }

    /**
     * WI-A6 (default per DECISIONS D7): drop the proof-of-delivery OTP (a live
     * credential-like value) and coarsen exact home coordinates to ~3 dp (~110 m)
     * so the export never carries a customer's precise doorstep location. The
     * order is still the owner's data — this only blunts the sharpest PII.
     */
    private function scrubOrderRow(array $row): array
    {
        unset($row['pod_otp']);
        foreach (['delivery_lat', 'delivery_lng'] as $col) {
            if (isset($row[$col]) && is_numeric($row[$col])) {
                $row[$col] = round((float) $row[$col], 3);
            }
        }

        return $row;
    }

    /** Overridable no-op hook between streaming and packing (see build()). */
    protected function afterDatasets(string $work): void {}

    /**
     * WI-D1: copy the store's real media files into media/<type>/ inside the ZIP,
     * capped and deduped, writing media_index.json. Everything is streamed via a
     * cursor + stream-copy so a media-heavy store never blows memory.
     */
    private function collectMedia(string $work, Store $store): array
    {
        $c = new MediaCollector($work, self::MEDIA_CAP_BYTES);

        // Store logo (storage/store/logo/) + cover (storage/store/cover/).
        $c->add('restaurant', $store->main_image ?? null, ['store/logo', 'restaurant']);
        $c->add('restaurant', $store->cover_image ?? null, ['store/cover', 'restaurant']);

        // Menu item images + their gallery images.
        foreach (Menu::where('store_id', $store->id)->select('id', 'image', 'gallery_images')->cursor() as $item) {
            $c->add('menu', $item->image ?? null, ['menu']);
            foreach ((array) ($item->gallery_images ?: []) as $g) {
                $c->add('menu', is_string($g) ? $g : ($g['image'] ?? null), ['menu']);
            }
        }

        // Managed media assets + PDF/booklet menus (both served from storage/menu).
        foreach (MediaAsset::where('store_id', $store->id)->select('id', 'filename')->cursor() as $asset) {
            $c->add('media_assets', $asset->filename ?? null, ['menu']);
        }
        foreach (ImageMenu::where('store_id', $store->id)->select('id', 'image')->cursor() as $im) {
            $c->add('image_menus', $im->image ?? null, ['menu']);
        }

        // Order voice notes live on the PRIVATE disk at voice-notes/<rid>/<file>.
        foreach (OrderVoiceNote::where('store_id', $store->id)->select('id', 'filename')->cursor() as $vn) {
            $c->add('voice_notes', $vn->filename ?? null, ['voice-notes/'.$store->id], true);
        }

        return $c->finish();
    }

    /**
     * Whether this server can AES-256 encrypt a ZIP entry (WI-A4). Overridable so
     * tests can force the no-capability path. Exposed to the UI via
     * encryptionAvailable() so the password field can be disabled up front.
     */
    protected function serverCanEncrypt(): bool
    {
        return method_exists(ZipArchive::class, 'setEncryptionName') && defined('ZipArchive::EM_AES_256');
    }

    /** UI capability probe: is password protection available on this server? */
    public static function encryptionAvailable(): bool
    {
        return method_exists(ZipArchive::class, 'setEncryptionName') && defined('ZipArchive::EM_AES_256');
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
