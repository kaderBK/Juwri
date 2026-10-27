<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use App\Models\StoreView;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Modules\LegacyCompat\Http\Controllers\LegacyRedirectController;
use Tests\Modules\LegacyCompat\Support\ImportsReplayFixture;
use Tests\Modules\LegacyCompat\Support\ReplayUrlGenerator;
use Tests\TestCase;

/**
 * M7-B: the QR replay test (plan §7). Every old printed-URL form of the synthetic legacy set is replayed over HTTP
 * against the migrated install and must answer exactly as the golden file says: the status, the exact Location, the
 * Cache-Control rule, the scan counted for the store and the table/room context of the page.
 *
 * The golden file (tests/Fixtures/LegacyCompat/replay.jsonl) is the frozen answer of the whole set, one JSON object
 * per line. It is written ONLY by an explicit run with LEGACY_REPLAY_REGEN=1; every other run compares the file with
 * the freshly generated set, byte for byte.
 *
 * The `/restaurant/{slug}` forms are part of the set, but they answer with CORE's own answer (200 for a store that
 * exists, 404 for one that does not): the module's route for that shape is a Laravel fallback route, so core's
 * non-fallback route is matched first (security review B2) and the module's 301 is never reached over HTTP. The
 * module's 301 contract itself is asserted on the controller ({@see test_the_module_301s_the_store_page_form_to_the_slug_with_the_query_kept()}).
 *
 * The synthetic set only (J19): invented slugs, keys and tokens, never a value of a real install.
 */
class LegacyQrReplayTest extends TestCase
{
    use ImportsReplayFixture;
    use RefreshDatabase;

    /** The frozen answer of the whole set. */
    private const GOLDEN_FILE = 'tests/Fixtures/LegacyCompat/replay.jsonl';

    /** The fewest cases the set must hold to be a regression net. */
    private const MINIMUM_CASES = 400;

    /** @var list<array{id: string, method: string, url: string, expect: array<string, mixed>}> */
    private array $cases = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootReplayStorefront();
        $this->importReplaySource();
        $this->planImportedOwners();
        $this->configureReplaySecrets();
        config([
            'legacy_compat.enabled' => true,
            // Two wrong keys per store stay under the cut-off, so a real key is still looked up.
            'abuse.table_key_misses_per_minute' => 5,
            'abuse.table_key_misses_per_minute_network' => 5,
        ]);
        $this->activateReplayModule();

        $this->cases = (new ReplayUrlGenerator($this->replayManifest, ReplayUrlGenerator::passphrases()))->cases();
    }

    /** The replay itself: every case, with the exact answer of the golden file. */
    public function test_every_replay_case_answers_as_expected(): void
    {
        $mismatches = [];
        $replayed = 0;

        foreach ($this->cases as $index => $case) {
            $replayed++;
            $mismatch = $this->replay($case, $index);
            if ($mismatch !== null) {
                $mismatches[] = $mismatch;
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_CASES,
            $replayed,
            'the replay set is too small to be a regression net'
        );
        $this->assertSame(
            [],
            $mismatches,
            'replayed '.$replayed.' cases, '.count($mismatches).' did not answer as expected: '."\n".implode("\n", $mismatches)
        );

        fwrite(STDERR, 'replayed '.$replayed.' cases, 100% as expected'."\n");
    }

    /**
     * The golden file: the frozen answer of the set. It is written only by an explicit run with LEGACY_REPLAY_REGEN=1.
     */
    public function test_the_replay_set_matches_the_golden_file(): void
    {
        $path = base_path(self::GOLDEN_FILE);
        $generated = $this->goldenJsonl();

        if (getenv('LEGACY_REPLAY_REGEN') === '1') {
            file_put_contents($path, $generated);
            $this->markTestSkipped('the golden file was regenerated: '.$path);
        }

        $this->assertFileExists(
            $path,
            'the golden file is missing: run this test with LEGACY_REPLAY_REGEN=1 to write it'
        );
        $this->assertSame(
            (string) file_get_contents($path),
            $generated,
            'the golden file does not match the generated replay set'
        );
    }

    /**
     * The slugs that collide with a core top-level path get no case at all (plan §5.4, OPEN): the module must not
     * answer them. The list is asserted by name so a new conflict cannot slip in unnoticed.
     */
    public function test_the_slugs_that_collide_with_a_core_path_are_skipped_by_name(): void
    {
        $generator = new ReplayUrlGenerator($this->replayManifest, ReplayUrlGenerator::passphrases());

        $skipped = $generator->skipped();

        $this->assertSame(
            [
                ['store_id' => 105, 'slug' => 'admin'],
                ['store_id' => 106, 'slug' => 'r'],
                ['store_id' => 107, 'slug' => 'ar'],
            ],
            array_map(
                static fn (array $entry): array => ['store_id' => $entry['store_id'], 'slug' => $entry['slug']],
                $skipped
            )
        );

        foreach ($skipped as $entry) {
            $this->assertNotSame('', $entry['reason'], 'a skipped store must say why it is skipped');
        }

        // No case may name a skipped slug as its own path segment.
        foreach ($this->cases as $case) {
            $segment = explode('/', ltrim(explode('?', $case['url'])[0], '/'))[0];
            $this->assertNotContains(
                $segment,
                ReplayUrlGenerator::RESERVED_SLUGS,
                'the case '.$case['id'].' names the reserved slug '.$segment
            );
        }
    }

    /**
     * The module's own 301 contract for `/restaurant/{slug}/{title}` (LEGACYCOMPAT-SPEC §3): the same path, the query
     * kept, nothing added, `public, max-age=86400`. Over HTTP core answers that path itself (the module route is a
     * fallback route), so the contract is checked on the controller itself.
     */
    public function test_the_module_301s_the_store_page_form_to_the_slug_with_the_query_kept(): void
    {
        $store = $this->firstReplayedStore();

        $response = app(LegacyRedirectController::class)
            ->storePage(Request::create('/x/y?utm_source=print&item=4'), $store->slug, 'Old Title');

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(
            url('/'.rawurlencode($store->slug)).'?utm_source=print&item=4',
            $response->headers->get('Location')
        );
        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * Replay one case and return null when it answered as expected, or a description of what was wrong.
     *
     * @param  array{id: string, method: string, url: string, expect: array<string, mixed>}  $case
     */
    private function replay(array $case, int $index): ?string
    {
        $expect = $case['expect'];
        $store = $this->storeOfCase($case);
        $before = $store !== null ? $store->views()->count() : StoreView::query()->count();

        // One client address per case: the 30 s scan de-duplication never hides a scan of the replay.
        $response = $this->withServerVariables(['REMOTE_ADDR' => self::clientAddress($index)])
            ->call($case['method'], $case['url']);

        $after = $store !== null ? $store->views()->count() : StoreView::query()->count();

        $problems = [];

        $status = $response->getStatusCode();
        if ($status !== $expect['status']) {
            $problems[] = 'status '.$status.' instead of '.$expect['status'];
        }

        if (isset($expect['location'])) {
            $location = (string) $response->headers->get('Location');
            if ($location !== $expect['location']) {
                $problems[] = 'location ['.$location.'] instead of ['.$expect['location'].']';
            }
            if (str_contains($location, 'qr-id')) {
                $problems[] = 'the location still carries the code';
            }
        }

        if (($expect['cache'] ?? null) === 'no-store') {
            $cache = (string) $response->headers->get('Cache-Control');
            if (! str_contains($cache, 'no-store')) {
                $problems[] = 'cache-control ['.$cache.'] has no no-store';
            }
            if (str_contains($cache, 'public') || str_contains($cache, 'max-age')) {
                $problems[] = 'cache-control ['.$cache.'] is cacheable';
            }
        }

        $scans = $after - $before;
        if ($scans !== $expect['scan']) {
            $problems[] = 'scans '.$scans.' instead of '.$expect['scan'];
        }

        if (array_key_exists('table', $expect)) {
            $table = $this->viewValue($response, 'table');
            $expectedTable = $expect['table'];
            if ($expectedTable === null) {
                if ($table !== null) {
                    $problems[] = 'table '.(string) $table->getKey().' instead of none';
                }
            } elseif ($table === null || (int) $table->getKey() !== (int) $expectedTable) {
                $problems[] = 'table '.($table === null ? 'none' : (string) $table->getKey()).' instead of '.$expectedTable;
            }
        }

        if (isset($expect['room_token'])) {
            $token = session('hotel_room_token');
            if ($token !== $expect['room_token']) {
                $problems[] = 'room token ['.(is_string($token) ? $token : 'none').'] instead of ['.$expect['room_token'].']';
            }
        }

        if ($problems === []) {
            return null;
        }

        return $case['id'].' ('.$case['method'].' '.$case['url'].'): '.implode('; ', $problems);
    }

    /**
     * The store a case belongs to (null for the cases that name no store).
     *
     * @param  array{id: string, method: string, url: string, expect: array<string, mixed>}  $case
     */
    private function storeOfCase(array $case): ?Store
    {
        if (preg_match('/^s(\d+)-/', $case['id'], $matches) !== 1) {
            return null;
        }

        return Store::find((int) $matches[1]);
    }

    /**
     * The value a view was rendered with (null when the view has no such key).
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     */
    private function viewValue(TestResponse $response, string $key): mixed
    {
        $view = $response->original;
        $data = $view instanceof View ? $view->getData() : [];

        return $data[$key] ?? null;
    }

    /** The golden file's content: one JSON object per line, in the order of the cases. */
    private function goldenJsonl(): string
    {
        $lines = [];
        foreach ($this->cases as $case) {
            $json = json_encode([
                'id' => $case['id'],
                'method' => $case['method'],
                'url' => $case['url'],
                'expect' => $case['expect'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($json === false) {
                $this->fail('the case '.$case['id'].' cannot be written as JSON: '.json_last_error_msg());
            }

            $lines[] = $json;
        }

        return implode("\n", $lines)."\n";
    }

    /** One client address per case (TEST-NET-2), so no scan is hidden by the de-duplication window. */
    private static function clientAddress(int $index): string
    {
        $index %= 62500;

        return '198.51.'.intdiv($index, 250).'.'.(($index % 250) + 1);
    }
}
