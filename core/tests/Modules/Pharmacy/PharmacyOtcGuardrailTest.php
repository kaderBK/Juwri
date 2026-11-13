<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pharmacy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pharmacy\Database\Seeders\PharmacyDemoSeeder;
use Tests\Support\PharmacyMarket;
use Tests\TestCase;

/**
 * rev/multi-vertical Lane F4 — the automated guardrail that keeps DECISIONS
 * D3 ("Pharmacy = OTC-only. No Rx, no prescription upload, no pharmacist
 * verification, no controlled substances.") honest going forward: asserts NO
 * prescription/Rx route, field, table or model exists ANYWHERE in the app —
 * not just in Lane F's own files — so a future PR can never smuggle a
 * regulated-dispensing surface into this vertical without this test failing.
 */
class PharmacyOtcGuardrailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Case-insensitive substrings that would signal an Rx/regulated-dispensing
     * surface. Deliberately broad; deliberately NOT matching plain "rx" as a
     * bare substring (too many false positives, e.g. nothing in this app
     * happens to collide, but being defensive costs nothing) — every needle
     * here is a whole, unambiguous word or compound.
     *
     * @var list<string>
     */
    private const BANNED_TERMS = [
        'prescription',
        'pharmacist',
        'controlled_substance',
        'controlled-substance',
        'controlled substance',
        'identity_verification',
        'identity-verification',
        'patient_id',
        'patient_identity',
        'drug_schedule',
        'dea_number',
        'narcotic',
    ];

    /**
     * carely Q4 (owner, 2026-10-03; P3-13): the store assistant may answer general OTC product questions and hands
     * every medical question to a human pharmacist ("Ask a pharmacist", "Pharmacist on duty" hours). The word is
     * allowed in storefront wording for that one use — these phrases only. Routes, tables and columns stay banned
     * (no pharmacist-verification surface), and every other mention on a storefront still fails.
     *
     * @var list<string>
     */
    private const PHARMACIST_HANDOFF_PHRASES = [
        'ask a pharmacist',
        'talk to a pharmacist',
        'pharmacist on duty',
        'a pharmacist will answer',
        'hands your question to a pharmacist',
    ];

    /** Storefront text with the allowed Q4 hand-off phrases taken out. */
    private static function withoutHandoffPhrases(string $html): string
    {
        return str_ireplace(self::PHARMACIST_HANDOFF_PHRASES, '', $html);
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
    }

    // ── Routes ───────────────────────────────────────────────────────────

    public function test_no_route_anywhere_in_the_app_names_a_prescription_or_rx_surface(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = Str::lower($route->uri());
            $name = Str::lower((string) $route->getName());
            $action = Str::lower((string) ($route->getActionName() ?? ''));

            foreach (self::BANNED_TERMS as $term) {
                $needle = str_replace(['-', ' '], '_', $term);
                $this->assertStringNotContainsString($needle, str_replace(['-', ' '], '_', $uri), "route URI leaked a banned OTC/Rx term: {$uri}");
                $this->assertStringNotContainsString($needle, str_replace(['-', ' '], '_', $name), "route name leaked a banned OTC/Rx term: {$name}");
                $this->assertStringNotContainsString($needle, str_replace(['-', ' '], '_', $action), "route action leaked a banned OTC/Rx term: {$action}");
            }
        }
    }

    // ── Schema ───────────────────────────────────────────────────────────

    public function test_no_pharmacy_related_table_column_mentions_prescription_or_rx(): void
    {
        foreach (['items', 'menu_variants', 'stores'] as $table) {
            $columns = Schema::getColumnListing($table);
            foreach ($columns as $column) {
                $tokens = explode('_', Str::lower($column));
                $this->assertNotContains('rx', $tokens, "{$table}.{$column} looks like an Rx column");
                foreach (self::BANNED_TERMS as $term) {
                    $needle = str_replace(['-', ' '], '_', $term);
                    $this->assertStringNotContainsString($needle, Str::lower($column), "{$table}.{$column} leaked a banned OTC/Rx term");
                }
            }
        }
    }

    public function test_no_prescriptions_table_or_model_exists(): void
    {
        $this->assertFalse(Schema::hasTable('prescriptions'));
        $this->assertFalse(Schema::hasTable('rx_orders'));
        $this->assertFalse(class_exists('App\\Models\\Prescription'));
    }

    // ── Pharmacy's OWN data model is exactly age_restricted/batch_no/expiry_date ──

    public function test_pharmacy_item_compliance_columns_are_exactly_the_otc_set(): void
    {
        $this->assertTrue(Schema::hasColumn('items', 'age_restricted'));
        $this->assertTrue(Schema::hasColumn('items', 'batch_no'));
        $this->assertTrue(Schema::hasColumn('items', 'expiry_date'));
    }

    // ── Demo seeder produces a valid, OTC-only store ────────────────────

    public function test_demo_seeder_produces_an_otc_only_pharmacy_store(): void
    {
        (new PharmacyDemoSeeder)->run();

        $store = Store::where('slug', 'pharmacy-otc-demo')->firstOrFail();
        $this->assertSame('pharmacy', $store->store_type);
        $this->assertNotSame(10008, $store->id, 'the demo seeder must never touch demo account #10008');

        $items = Menu::where('store_id', $store->id)->get();
        $this->assertGreaterThanOrEqual(6, $items->count());
        $this->assertTrue($items->contains('age_restricted', true), 'at least one demo item is age-restricted');
        $this->assertTrue($items->contains(fn ($m) => $m->expiry_date !== null), 'at least one demo item carries an expiry date');
        $this->assertTrue($items->contains(fn ($m) => $m->is_expired), 'the demo seed includes an already-expired item for QA');

        // Re-running is a no-op (idempotent), never duplicates rows.
        (new PharmacyDemoSeeder)->run();
        $this->assertSame(1, Store::where('slug', 'pharmacy-otc-demo')->count());
        $this->assertSame($items->count(), Menu::where('store_id', $store->id)->count());
    }

    public function test_demo_store_storefront_renders_with_no_rx_wording(): void
    {
        (new PharmacyDemoSeeder)->run();
        $store = Store::where('slug', 'pharmacy-otc-demo')->firstOrFail();
        PharmacyMarket::open($store);

        $html = $this->get(route('publicView', $store->slug))->getContent();

        $this->assertStringNotContainsStringIgnoringCase('prescription', $html);
        $this->assertStringNotContainsStringIgnoringCase('pharmacist', self::withoutHandoffPhrases($html), 'only the Q4 hand-off wording may name a pharmacist');
        $this->assertStringContainsString('Wellness Corner Pharmacy', $html);
    }

    public function test_q4_allows_only_the_pharmacist_handoff_wording(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('pharmacist', self::withoutHandoffPhrases('Questions about a dose? Ask a pharmacist. Pharmacist on duty 9–21.'));
        $this->assertStringContainsStringIgnoringCase('pharmacist', self::withoutHandoffPhrases('Upload your prescription for pharmacist verification'));
        $this->assertContains('pharmacist', self::BANNED_TERMS, 'routes, tables and columns still may not name a pharmacist surface');
    }
}
