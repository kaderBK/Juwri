<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ai;

use Juwri\Modules\Ai\Jobs\LangChromeTranslateJob;
use Juwri\Core\Models\Language;
use Juwri\Modules\Ai\Services\TranslatorService;
use Juwri\Core\Services\LangPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Lang-pipeline ENGINE tests (Lane A). Every test runs against a THROWAWAY lang
 * tree injected through the constructor — never the real core/lang (HARD RULE 2).
 * The AI stack is never called here; applyMachine() is fed pre-baked strings.
 */
class LangPipelineEngineTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        // Pin the source locale so the heuristic compares locale values against en
        // (the service reads config('settings.lang') per the coverage() contract).
        config(['settings.lang' => 'en', 'app.default_language' => 'en']);
        $this->tmp = sys_get_temp_dir().'/langpipe-'.uniqid();
        File::makeDirectory($this->tmp.'/en', 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function svc(): LangPipelineService
    {
        return new LangPipelineService($this->tmp);
    }

    private function seedLang(string $code, array $map): void
    {
        if (! File::isDirectory($this->tmp.'/'.$code)) {
            File::makeDirectory($this->tmp.'/'.$code, 0755, true);
        }
        File::put($this->tmp.'/'.$code.'/lang.php', "<?php \n return ".var_export($map, true).";\n");
    }

    private function seedMeta(string $code, array $meta): void
    {
        if (! File::isDirectory($this->tmp.'/'.$code)) {
            File::makeDirectory($this->tmp.'/'.$code, 0755, true);
        }
        File::put($this->tmp.'/'.$code.'/meta.json', json_encode($meta));
    }

    private function readMetaRaw(string $code): array
    {
        $f = $this->tmp.'/'.$code.'/meta.json';

        return File::exists($f) ? (json_decode((string) File::get($f), true) ?: []) : [];
    }

    private function readLangRaw(string $code): array
    {
        $f = $this->tmp.'/'.$code.'/lang.php';

        return File::exists($f) ? (include $f) : [];
    }

    // ── A1: sidecar I/O + keys() statuses ─────────────────────────────────────

    public function test_keys_derives_status_from_heuristic_and_sidecar(): void
    {
        $this->seedLang('en', ['save' => 'Save', 'cancel' => 'Cancel', 'delete' => 'Delete', 'edit' => 'Edit']);
        // ar: save translated (human by heuristic), cancel === en (placeholder),
        // delete absent from lang.php, edit forced to machine by sidecar.
        $this->seedLang('ar', ['save' => 'حفظ', 'cancel' => 'Cancel', 'edit' => 'Edit']);
        $this->seedMeta('ar', ['edit' => ['s' => 'machine', 'h' => sha1('Edit'), 'at' => '2026-08-25']]);

        $rows = collect($this->svc()->keys('ar'))->keyBy('key');

        $this->assertSame('human', $rows['save']['status']);
        $this->assertSame('placeholder', $rows['cancel']['status']);
        $this->assertSame('absent', $rows['delete']['status']);
        $this->assertSame('machine', $rows['edit']['status']); // sidecar wins over heuristic
        $this->assertNull($rows['delete']['value']);
        $this->assertSame('Save', $rows['save']['en']);
    }

    public function test_keys_search_and_status_filter(): void
    {
        $this->seedLang('en', ['save' => 'Save', 'cancel' => 'Cancel', 'delete' => 'Delete']);
        $this->seedLang('ar', ['save' => 'حفظ', 'cancel' => 'Cancel']);

        // substring q on en, case-insensitive
        $hit = $this->svc()->keys('ar', 'canc');
        $this->assertCount(1, $hit);
        $this->assertSame('cancel', $hit[0]['key']);

        // status filter
        $placeholders = $this->svc()->keys('ar', null, 'placeholder');
        $this->assertSame(['cancel'], array_column($placeholders, 'key'));

        $absent = $this->svc()->keys('ar', null, 'absent');
        $this->assertSame(['delete'], array_column($absent, 'key'));
    }

    public function test_keys_orders_by_key_and_caps_limit(): void
    {
        $this->seedLang('en', ['zeta' => 'Z', 'alpha' => 'A', 'mid' => 'M']);
        $this->seedLang('ar', ['zeta' => 'Z', 'alpha' => 'A', 'mid' => 'M']);

        $capped = $this->svc()->keys('ar', null, null, 2);
        $this->assertSame(['alpha', 'mid'], array_column($capped, 'key')); // sorted, capped at 2
    }

    public function test_meta_read_is_tolerant_of_missing_and_corrupt(): void
    {
        $this->seedLang('en', ['save' => 'Save']);
        $this->seedLang('ar', ['save' => 'Save']); // no meta.json at all
        // Should not throw; derives placeholder (=== en) without a sidecar.
        $rows = $this->svc()->keys('ar');
        $this->assertSame('placeholder', $rows[0]['status']);

        // Corrupt meta.json → treated as empty, still no throw.
        File::put($this->tmp.'/ar/meta.json', '{ this is not json');
        $rows = $this->svc()->keys('ar');
        $this->assertSame('placeholder', $rows[0]['status']);
    }

    public function test_keys_returns_empty_when_base_path_absent(): void
    {
        $svc = new LangPipelineService($this->tmp.'/does-not-exist');
        $this->assertSame([], $svc->keys('ar'));
    }

    // ── A2: coverage() + bootstrap() ──────────────────────────────────────────

    public function test_coverage_counts_active_targets_excluding_source(): void
    {
        // Migration pre-seeds en (source, pos 0) + fr (active, pos 1). Add ar + an inactive de.
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1, 'position' => 2]);
        Language::updateOrCreate(['code' => 'de'], ['name' => 'German', 'direction' => 'ltr', 'active' => 0, 'position' => 3]); // inactive: excluded

        $this->seedLang('en', ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']);
        // ar: a human, b placeholder(===en), c machine(sidecar), d absent
        $this->seedLang('ar', ['a' => 'ايه', 'b' => 'B', 'c' => 'C']);
        $this->seedMeta('ar', ['c' => ['s' => 'machine', 'h' => sha1('C'), 'at' => '2026-08-25']]);
        // fr: all placeholders (===en)
        $this->seedLang('fr', ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']);

        $cov = $this->svc()->coverage();

        $this->assertSame(4, $cov['en_total']);
        $this->assertSame(['fr', 'ar'], array_column($cov['locales'], 'code')); // en(source)+de(inactive) excluded, position order

        $ar = collect($cov['locales'])->firstWhere('code', 'ar');
        $this->assertSame('rtl', $ar['direction']);
        $this->assertSame(1, $ar['human']);
        $this->assertSame(1, $ar['placeholder']);
        $this->assertSame(1, $ar['machine']);
        $this->assertSame(1, $ar['absent']);
        $this->assertSame(4, $ar['total']);
        $this->assertSame(50.0, $ar['pct_done']); // (human1+machine1)/4

        $fr = collect($cov['locales'])->firstWhere('code', 'fr');
        $this->assertSame(0.0, $fr['pct_done']); // all placeholder
    }

    public function test_bootstrap_classifies_idempotent_and_never_downgrades(): void
    {
        $this->seedLang('en', ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D']);
        // a: translated (human), b: === en (placeholder), c: empty (placeholder),
        // d: pre-existing human sidecar but value now === en → must NOT downgrade.
        $this->seedLang('ar', ['a' => 'ايه', 'b' => 'B', 'c' => '', 'd' => 'D']);
        $this->seedMeta('ar', ['d' => ['s' => 'human', 'h' => sha1('D'), 'at' => '2026-08-25']]);

        $counts = $this->svc()->bootstrap('ar');
        $this->assertSame(['placeholder' => 2, 'human' => 1], $counts); // b+c placeholder, a human; d skipped

        $meta = $this->readMetaRaw('ar');
        $this->assertSame('human', $meta['a']['s']);
        $this->assertSame(sha1('A'), $meta['a']['h']);
        $this->assertSame('placeholder', $meta['b']['s']);
        $this->assertSame('placeholder', $meta['c']['s']);
        $this->assertSame('human', $meta['d']['s']); // never downgraded

        // Idempotent: nothing new to classify on a second run.
        $again = $this->svc()->bootstrap('ar');
        $this->assertSame(['placeholder' => 0, 'human' => 0], $again);
    }

    // ── A3: QA gates (table-driven) ───────────────────────────────────────────

    public function test_qa_placeholders_table(): void
    {
        $cases = [
            // [en, out, expected, label]
            ['Deleted :count items', 'تم حذف :count عنصر', true, 'token present both'],
            ['You have :amt left', 'لديك رصيد متبق', false, ':amt lost'],
            ['Hi :first :last', ':last :first مرحبا', true, 'reordered tokens pass'],
            ['Save :file', 'احفظ :file :extra', false, 'extra token added'],
            [':x and :x', 'فقط :x', false, 'multiset count matters (2 vs 1)'],
            ['Status: ok', 'الحالة: تمام', true, 'colon-space is not a token'],
            ['Save', 'احفظ', true, 'empty multiset equality'],
        ];

        foreach ($cases as [$en, $out, $expected, $label]) {
            $this->assertSame($expected, LangPipelineService::qaPlaceholders($en, $out), $label);
        }
    }

    public function test_qa_plural_table(): void
    {
        $plural = '{1} :count item|[2,*] :count items';
        $cases  = [
            ['Save', 'احفظ فقط', true, 'non-plural en ignores the gate'],
            [$plural, '{1} :count عنصر|[2,*] :count عناصر', true, 'pipe + {1}/[2,*] preserved'],
            [$plural, 'عنصر واحد', false, 'pipe drop fails (1 vs 0)'],
            ['{1} x|[2,*] y', '{1} a|[2,3] b', false, '[2,*] became [2,3]'],
            [$plural, ':count عنصر|:count عناصر', false, '{1}/[2,*] both lost'],
        ];

        foreach ($cases as [$en, $out, $expected, $label]) {
            $this->assertSame($expected, LangPipelineService::qaPlural($en, $out), $label);
        }
    }

    // ── A4: markHuman() + applyMachine() + approve/reject ──────────────────────

    public function test_mark_human_writes_row_and_sidecar_and_clears_candidate(): void
    {
        $this->seedLang('en', ['greet' => 'Hello :name']);
        $this->seedLang('ar', ['greet' => 'Hello :name']);
        // a stale review candidate that markHuman must overwrite/clear
        $this->seedMeta('ar', ['greet' => ['s' => 'review', 'cand' => 'x', 'why' => 'placeholders', 'h' => sha1('old'), 'at' => '2026-01-01']]);

        $this->svc()->markHuman('ar', 'greet', 'مرحبا :name');

        $this->assertSame('مرحبا :name', $this->readLangRaw('ar')['greet']);
        $m = $this->readMetaRaw('ar')['greet'];
        $this->assertSame('human', $m['s']);
        $this->assertSame(sha1('Hello :name'), $m['h']);
        $this->assertArrayNotHasKey('cand', $m);
        $this->assertArrayNotHasKey('why', $m);
        $this->assertSame(date('Y-m-d'), $m['at']);
    }

    public function test_apply_machine_pass_writes_review_fails_and_skips_human(): void
    {
        $this->seedLang('en', [
            'ok'     => 'Deleted :count items',
            'badtok' => 'You have :amt left',
            'plural' => '{1} :count item|[2,*] :count items',
            'lowc'   => 'Save',
            'frozen' => 'Edit',
        ]);
        $this->seedLang('ar', [
            'ok'     => 'Deleted :count items', // placeholder → translatable
            'badtok' => 'You have :amt left',
            'plural' => '{1} :count item|[2,*] :count items',
            'lowc'   => 'Save',
            'frozen' => 'Edit',
        ]);
        $this->seedMeta('ar', ['frozen' => ['s' => 'human', 'h' => sha1('Edit'), 'at' => '2026-01-01']]);

        $res = $this->svc()->applyMachine('ar', [
            'ok'     => ['value' => 'تم حذف :count عنصر'],                 // QA pass → machine
            'badtok' => ['value' => 'لديك رصيد'],                          // missing :amt → review(placeholders)
            'plural' => ['value' => ':count عنصر و :count عنصر'],          // tokens kept, pipe dropped → review(plural)
            'lowc'   => ['value' => 'احفظ', 'lowconf' => true],            // QA ok but lowconf → review(lowconf)
            'frozen' => ['value' => 'يحرر'],                              // human → skipped
        ]);

        $this->assertSame(['written' => 1, 'queued_review' => 3, 'skipped_human' => 1], $res);

        $lang = $this->readLangRaw('ar');
        $meta = $this->readMetaRaw('ar');

        // pass → written to lang.php + machine
        $this->assertSame('تم حذف :count عنصر', $lang['ok']);
        $this->assertSame('machine', $meta['ok']['s']);

        // fails → lang.php untouched (still the en placeholder), sidecar review + cand + why
        $this->assertSame('You have :amt left', $lang['badtok']);
        $this->assertSame('review', $meta['badtok']['s']);
        $this->assertSame('placeholders', $meta['badtok']['why']);
        $this->assertSame('لديك رصيد', $meta['badtok']['cand']);
        $this->assertSame('plural', $meta['plural']['why']);
        $this->assertSame('lowconf', $meta['lowc']['why']);

        // human key never touched
        $this->assertSame('Edit', $lang['frozen']);
        $this->assertSame('human', $meta['frozen']['s']);
    }

    public function test_approve_and_reject_candidate(): void
    {
        $this->seedLang('en', ['a' => 'Hi', 'b' => 'Bye']);
        $this->seedLang('ar', ['a' => 'Hi', 'b' => 'Bye']);
        $this->seedMeta('ar', [
            'a' => ['s' => 'review', 'cand' => 'مرحبا', 'why' => 'lowconf', 'h' => sha1('Hi'), 'at' => '2026-01-01'],
            'b' => ['s' => 'review', 'cand' => 'وداعا', 'why' => 'lowconf', 'h' => sha1('Bye'), 'at' => '2026-01-01'],
        ]);

        $this->svc()->approveCandidate('ar', 'a');
        $this->svc()->rejectCandidate('ar', 'b');

        $lang = $this->readLangRaw('ar');
        $meta = $this->readMetaRaw('ar');

        // approve: candidate lands in lang.php, sidecar → machine, cand/why dropped
        $this->assertSame('مرحبا', $lang['a']);
        $this->assertSame('machine', $meta['a']['s']);
        $this->assertArrayNotHasKey('cand', $meta['a']);

        // reject: lang.php untouched (still en), sidecar → placeholder, cand/why dropped
        $this->assertSame('Bye', $lang['b']);
        $this->assertSame('placeholder', $meta['b']['s']);
        $this->assertArrayNotHasKey('cand', $meta['b']);
        $this->assertArrayNotHasKey('why', $meta['b']);

        // review queue now empty; a non-review approve is a no-op
        $this->assertSame([], $this->svc()->reviewQueue('ar'));
        $this->svc()->approveCandidate('ar', 'a'); // already machine → no throw / no change
        $this->assertSame('machine', $this->readMetaRaw('ar')['a']['s']);
    }

    public function test_review_queue_lists_only_review_rows_ordered(): void
    {
        $this->seedLang('en', ['z' => 'Z', 'a' => 'A', 'm' => 'M']);
        $this->seedLang('ar', ['z' => 'Z', 'a' => 'A', 'm' => 'M']);
        $this->seedMeta('ar', [
            'z' => ['s' => 'review', 'cand' => 'ز', 'why' => 'placeholders', 'h' => sha1('Z'), 'at' => '2026-01-01'],
            'a' => ['s' => 'review', 'cand' => 'أ', 'why' => 'lowconf', 'h' => sha1('A'), 'at' => '2026-01-01'],
            'm' => ['s' => 'machine', 'h' => sha1('M'), 'at' => '2026-01-01'],
        ]);

        $q = $this->svc()->reviewQueue('ar');
        $this->assertSame(['a', 'z'], array_column($q, 'key')); // ordered, machine excluded
        $this->assertSame('A', $q[0]['en']);
        $this->assertSame('أ', $q[0]['candidate']);
        $this->assertSame('lowconf', $q[0]['why']);
    }

    // ── A5: LangChromeTranslateJob (faked translator, progress, guards) ────────

    /** Fake translator: maps en source string → [translation, lowconf], bypasses AiService. */
    private function fakeTranslator(array $map): TranslatorService
    {
        return new class($map) extends TranslatorService
        {
            public function __construct(private array $map = [])
            {
                // deliberately skip parent ctor — no AiService, never calls a model
            }

            public function translateBatch(array $records, string $targetLang, array $opts = []): array
            {
                $out = [];
                foreach ($records as $ref => $fields) {
                    $en          = (string) ($fields['t'] ?? '');
                    [$val, $low] = $this->map[$en] ?? [$en, false];
                    $out[$ref]   = ['t' => $val, '_lowconf' => $low];
                }

                return $out;
            }
        };
    }

    public function test_job_translates_pending_keys_good_and_review(): void
    {
        $this->seedLang('en', ['greeting' => 'Hello :name', 'broken' => 'You have :amt']);
        $this->seedLang('ar', ['greeting' => 'Hello :name', 'broken' => 'You have :amt']); // both placeholder
        $this->app->instance(LangPipelineService::class, new LangPipelineService($this->tmp));

        $translator = $this->fakeTranslator([
            'Hello :name'   => ['مرحبا :name', false], // token kept → machine
            'You have :amt' => ['لديك', false],         // token dropped → review(placeholders)
        ]);

        new LangChromeTranslateJob('ar')->handle($translator);

        $p = Cache::get(LangChromeTranslateJob::progressKey('ar'));
        $this->assertSame('done', $p['status']);
        $this->assertSame(2, $p['total']);
        $this->assertSame(2, $p['done']);
        $this->assertSame(1, $p['written']);
        $this->assertSame(1, $p['review']);

        $lang = $this->readLangRaw('ar');
        $meta = $this->readMetaRaw('ar');
        $this->assertSame('مرحبا :name', $lang['greeting']);
        $this->assertSame('machine', $meta['greeting']['s']);
        $this->assertSame('You have :amt', $lang['broken']); // untouched
        $this->assertSame('review', $meta['broken']['s']);
        $this->assertSame('placeholders', $meta['broken']['why']);
        $this->assertSame('لديك', $meta['broken']['cand']);
    }

    public function test_job_refuses_source_locale(): void
    {
        $this->seedLang('en', ['greeting' => 'Hello']);
        $this->app->instance(LangPipelineService::class, new LangPipelineService($this->tmp));

        new LangChromeTranslateJob('en')->handle($this->fakeTranslator([]));

        $p = Cache::get(LangChromeTranslateJob::progressKey('en'));
        $this->assertSame('error', $p['status']);
        $this->assertStringContainsString('source locale', $p['message']);
        $this->assertFileDoesNotExist($this->tmp.'/en/meta.json'); // never wrote a sidecar
    }

    public function test_job_error_path_sets_error_progress(): void
    {
        $this->seedLang('en', ['greeting' => 'Hello']);
        $this->seedLang('ar', ['greeting' => 'Hello']);
        $this->app->instance(LangPipelineService::class, new LangPipelineService($this->tmp));

        $boom = new class extends TranslatorService
        {
            public function __construct()
            {
            }

            public function translateBatch(array $records, string $targetLang, array $opts = []): array
            {
                throw new \RuntimeException('ai down');
            }
        };

        try {
            new LangChromeTranslateJob('ar')->handle($boom);
            $this->fail('expected the job to rethrow');
        } catch (\RuntimeException $e) {
            $this->assertSame('ai down', $e->getMessage());
        }

        $p = Cache::get(LangChromeTranslateJob::progressKey('ar'));
        $this->assertSame('error', $p['status']);
    }

    public function test_job_progress_key_matches_service(): void
    {
        $this->assertSame(
            LangPipelineService::progressKey('ar'),
            LangChromeTranslateJob::progressKey('ar')
        );
    }

    // ── A7: cross-cutting — review candidate surfaced in keys() + coverage() ───

    public function test_review_candidate_surfaces_in_keys_and_coverage(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1, 'position' => 9]);
        $this->seedLang('en', ['hi' => 'Hi :name', 'bye' => 'Bye']);
        $this->seedLang('ar', ['hi' => 'Hi :name', 'bye' => 'Bye']);
        $this->seedMeta('ar', ['hi' => ['s' => 'review', 'cand' => 'مرحبا', 'why' => 'placeholders', 'h' => sha1('Hi :name'), 'at' => '2026-01-01']]);

        // keys(): the review row exposes its candidate + status.
        $rows = collect($this->svc()->keys('ar'))->keyBy('key');
        $this->assertSame('review', $rows['hi']['status']);
        $this->assertSame('مرحبا', $rows['hi']['candidate']);
        $this->assertNull($rows['bye']['candidate']);

        // coverage(): the review row is counted under `review`, not human/machine.
        $ar = collect($this->svc()->coverage()['locales'])->firstWhere('code', 'ar');
        $this->assertSame(1, $ar['review']);
        $this->assertSame(1, $ar['placeholder']); // bye === en
        $this->assertSame(0.0, $ar['pct_done']);   // review does not count as done
    }

    public function test_apply_machine_preserves_edge_case_keys_without_reslugging(): void
    {
        // Canonical keys that Str::slug would mangle: a trailing-underscore plural
        // template, an UPPERCASE key that would lowercase-collide with a real
        // sibling, and a symbol key that would slug to ''.
        $this->seedLang('en', [
            'n_' => '{1} :count x|[2,*] :count y',
            'ALLOW_ADVANCED' => 'ALLOW ADVANCED',
            'allow_advanced' => 'allow advanced (lower)',
            '#' => '#',
        ]);
        $this->seedLang('fr', []);

        $res = $this->svc()->applyMachine('fr', [
            'n_' => ['value' => '{1} :count أ|[2,*] :count ب'],
            'ALLOW_ADVANCED' => ['value' => 'سماح متقدّم'],
            'allow_advanced' => ['value' => 'سماح (صغير)'],
            '#' => ['value' => '#'],
        ]);

        $this->assertSame(4, $res['written']);
        $this->assertSame(0, $res['queued_review']);

        $fr = include $this->tmp.'/fr/lang.php';
        // Each key lands on itself — no lowercasing, no trailing-_ loss, no '' collision.
        $this->assertSame('{1} :count أ|[2,*] :count ب', $fr['n_']);
        $this->assertSame('سماح متقدّم', $fr['ALLOW_ADVANCED']);
        $this->assertSame('سماح (صغير)', $fr['allow_advanced']);
        $this->assertSame('#', $fr['#']);
        $this->assertArrayNotHasKey('n', $fr);   // 'n_' was NOT slugged to 'n'
        $this->assertArrayNotHasKey('', $fr);    // symbol key did NOT collide to ''
    }
}
