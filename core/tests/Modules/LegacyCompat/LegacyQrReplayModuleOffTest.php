<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\QrIdDecoder;
use Tests\Modules\LegacyCompat\Support\ImportsReplayFixture;
use Tests\Modules\LegacyCompat\Support\ReplayUrlGenerator;
use Tests\TestCase;

/**
 * M7-B: the module OFF (plan §7 "Module-off test"). The same migrated install, the same old printed links, but the
 * switch off: the module must behave like a fresh install — no legacy route, no decoding, no redirect — while the
 * plain storefront forms keep answering exactly as they do with the module on (no behaviour change).
 *
 * The synthetic set only (J19): invented slugs and keys, never a value of a real install.
 */
class LegacyQrReplayModuleOffTest extends TestCase
{
    use ImportsReplayFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootReplayStorefront();
        $this->importReplaySource();
        $this->planImportedOwners();
        $this->configureReplaySecrets();
        // The module is off: no middleware, no legacy route, no decoding, no decorator.
        config(['legacy_compat.enabled' => false]);
    }

    /** Off: an old printed code is not decoded — the page is served direct, with no redirect and no scan. */
    public function test_an_old_code_is_not_decoded_while_the_module_is_off(): void
    {
        $store = $this->firstReplayedStore();
        $qr = urlencode(QrIdDecoder::encode($store->slug, ReplayUrlGenerator::passphrases()[0]));

        $response = $this->get('/'.$store->slug.'?qr-id='.$qr);

        $response->assertOk();
        $this->assertNull($response->headers->get('Location'), 'the page was redirected while the module is off');
        $this->assertSame(0, $store->views()->count(), 'a scan was counted while the module is off');
    }

    /**
     * Off: the `/restaurant/…` forms answer with core's own answer (the storefront for a store that exists, 404 for
     * one that does not) — never with the module's 301 to the slug.
     */
    public function test_the_restaurant_forms_answer_with_cores_own_answer_while_the_module_is_off(): void
    {
        $store = $this->firstReplayedStore();
        $slug = rawurlencode($store->slug);

        foreach (['/restaurant/'.$slug, '/restaurant/'.$slug.'/some-title', '/restaurant/'.$store->id] as $path) {
            $response = $this->get($path);
            $status = $response->getStatusCode();

            $this->assertNotSame(301, $status, $path.' was redirected while the module is off');
            $this->assertNotSame(302, $status, $path.' was redirected while the module is off');
            $this->assertStringNotContainsString(
                '/'.$slug,
                (string) $response->headers->get('Location'),
                $path.' points at the slug while the module is off'
            );
        }

        // A store that does not exist: 404, whatever the module is.
        $this->get('/restaurant/no-such-store')->assertNotFound();
        $this->get('/restaurant/no-such-store/some-title')->assertNotFound();
    }

    /** Off: the module registers no route at all, and registering while off adds none. */
    public function test_no_legacy_route_exists_while_the_module_is_off(): void
    {
        $this->assertNoLegacyRoute();

        LegacyCompatRoutes::register();
        Route::getRoutes()->refreshNameLookups();

        $this->assertNoLegacyRoute();
        $this->assertNull(Route::getRoutes()->getByName(LegacyCompatRoutes::NAME_PREFIX.'store_page'));
    }

    /**
     * Off and on answer the plain storefront forms the same: the same status and the same table context, and no scan
     * either way. The module changes the old forms only.
     */
    public function test_the_plain_storefront_forms_answer_the_same_with_and_without_the_module(): void
    {
        $store = $this->firstReplayedStore();
        $table = $store->tables()->where('area_type', 'table')->firstOrFail();

        $off = $this->plainAnswers($store, (string) $table->key);

        // The module on, in the same test: the same requests must answer the same.
        config(['legacy_compat.enabled' => true]);
        $this->activateReplayModule();

        $on = $this->plainAnswers($store, (string) $table->key);

        $this->assertSame($off, $on, 'the module changed the answer of a plain storefront form');
        $this->assertSame(200, $off['plain']['status']);
        $this->assertSame(200, $off['table']['status']);
        $this->assertSame((int) $table->getKey(), $off['table']['table']);
        $this->assertSame(0, $off['scans'], 'a scan was counted without an old code');
    }

    /**
     * The plain forms of one store: the status, the table context and the scans of each.
     *
     * @return array{plain: array{status: int, table: ?int}, table: array{status: int, table: ?int}, scans: int}
     */
    private function plainAnswers(Store $store, string $tableKey): array
    {
        $plain = $this->get('/'.$store->slug);
        $withTable = $this->get('/'.$store->slug.'?table='.$tableKey);

        return [
            'plain' => ['status' => $plain->getStatusCode(), 'table' => $this->tableIdOf($plain)],
            'table' => ['status' => $withTable->getStatusCode(), 'table' => $this->tableIdOf($withTable)],
            'scans' => $store->views()->count(),
        ];
    }

    /**
     * The table a page was rendered with (null when the page has no table).
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     */
    private function tableIdOf(TestResponse $response): ?int
    {
        $view = $response->original;
        $data = $view instanceof View ? $view->getData() : [];
        $table = $data['table'] ?? null;

        return $table === null ? null : (int) $table->getKey();
    }

    private function assertNoLegacyRoute(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertFalse(
                str_starts_with((string) $route->getName(), LegacyCompatRoutes::NAME_PREFIX),
                'a legacy route is registered while the module is off: '.$route->uri()
            );
        }
    }
}
