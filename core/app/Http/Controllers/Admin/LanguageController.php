<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Requests\Admin\LanguageRequest;
use Juwri\Core\Http\Requests\Admin\ReorderRequest;
use Juwri\Core\Http\Requests\Admin\TranslationsRequest;
use Juwri\Core\Models\Language;
use Juwri\Core\Services\LangPipelineService;
use Juwri\Core\Services\LanguagePackService;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Support\Admin\Cells;
use Juwri\Core\Support\Admin\DataTable;
use Juwri\Modules\Ai\Jobs\LangChromeTranslateJob;

/**
 * Admin › Languages: the site languages, their translation folders under
 * `lang/<code>` and the translate screen; the second half is the
 * machine-translate pipeline (LANG-PIPELINE) and language packs.
 */
class LanguageController extends Controller
{
    use AnswersAdminPanel;

    public function __construct()
    {
        $this->authorizeResource(Language::class, 'language');
    }

    public function index(Request $request): View|JsonResponse
    {
        if ($request->ajax()) {
            $default = $this->defaultCode();

            return DataTable::make($request, [0 => 'position', 1 => 'code', 2 => 'name', 3 => 'direction', 4 => 'active'], ['name'])
                ->respond(Language::query(), fn (Language $language) => [
                    Cells::reorderHandle($language->id),
                    Cells::text(strtoupper((string) $language->code)),
                    Cells::html(e((string) $language->name).' <small class="text-muted">'.($language->code === $default ? e('('.___('Default').')') : '').'</small>'),
                    Cells::text(strtoupper((string) $language->direction)),
                    Cells::status((int) $language->active === 1, ___('Active'), ___('Disabled')),
                    Cells::actions(
                        Cells::editLink(route('admin.languages.edit', $language->id)),
                        Cells::iconLink(route('admin.languages.translates', $language->code), 'language', ___('Translate')),
                        $language->code === $default ? '' : $this->deleteForm($language),
                    ),
                ]);
        }

        // LANG-PIPELINE: per-locale coverage dashboard on the index (no sidebar item).
        return view('admin.languages.index', [
            'coverage' => $this->pipelineService()->coverage(),
            'languageNames' => Language::pluck('name', 'code'),
        ]);
    }

    public function create(): View
    {
        return view('admin.languages.create');
    }

    /** New language: its folder starts as a copy of the default language's files. */
    public function store(LanguageRequest $request): JsonResponse
    {
        $code = trim((string) $request->validated('code'));

        try {
            $this->seedFolder($code);
        } catch (\Throwable $e) {
            report($e);

            return $this->panelFailed($e->getMessage());
        }

        $language = Language::create([
            'name' => $request->validated('name'),
            'code' => $code,
            'direction' => $request->validated('direction'),
        ]);

        if ($request->wantsDefault()) {
            PlatformSettings::set('lang', $language->code); // H3: stored in options, not .env
        }

        return $this->panelDone(___('Created Successfully'));
    }

    public function show(Language $language): RedirectResponse
    {
        return redirect()->route('admin.languages.edit', $language);
    }

    public function edit(Language $language): View
    {
        return view('admin.languages.edit', ['language' => $language]);
    }

    /** The default language must stay active and default; pick another default first. */
    public function update(LanguageRequest $request, Language $language): JsonResponse
    {
        $active = $request->boolean('active');

        if ($language->code === $this->defaultCode() && (! $request->wantsDefault() || ! $active)) {
            return $this->panelFailed(___(':language_name is the default language', ['language_name' => $language->name]));
        }

        $language->update([
            'name' => $request->validated('name'),
            'direction' => $request->validated('direction'),
            'active' => $active ? 1 : 0,
        ]);

        if ($request->wantsDefault() && $active) {
            PlatformSettings::set('lang', $language->code); // H3: stored in options, not .env
        }

        return $this->panelDone(___('Updated Successfully'));
    }

    public function destroy(Language $language): RedirectResponse
    {
        if ($language->code === $this->defaultCode()) {
            app_alert_error(___('You can not delete the default language.'));

            return back();
        }

        if ($this->isFolderName((string) $language->code)) {
            File::deleteDirectory($this->folder((string) $language->code));
        }
        $language->delete();

        app_alert_success(___('Deleted Successfully'));

        return back();
    }

    public function reorder(ReorderRequest $request): JsonResponse
    {
        $this->authorize('reorder', Language::class);

        $this->savePositions(Language::class, $request->order());

        return $this->panelDone(___('Updated Successfully'));
    }

    /** Translate screen: every PHP strings file of the language next to the default language. */
    public function translate(string $code): View|RedirectResponse
    {
        $language = Language::query()->where('code', $code)->firstOrFail();
        $this->authorize('update', $language);

        if (! $this->isFolderName($code) || ! is_dir($this->folder($code))) {
            app_alert_error(___('Language file not exists'));

            return back();
        }

        $default = $this->defaultCode();
        $translates = $defaultLanguage = [];
        foreach ($this->stringFiles($code) as $file) {
            $translates[$file] = trans($file, [], $code);
            $defaultLanguage[$file] = trans($file, [], $default);
        }

        return view('admin.languages.translate', compact('translates', 'language', 'defaultLanguage'));
    }

    /**
     * Save the translate screen. Only files that already exist in the
     * language's folder are written; anything else is refused before any
     * file is touched.
     */
    public function translateUpdate(TranslationsRequest $request, Language $language): RedirectResponse
    {
        $this->authorize('update', $language);

        $code = (string) $language->code;
        $files = $request->translationFiles();
        $known = $this->isFolderName($code) ? $this->stringFiles($code) : [];

        foreach (array_keys($files) as $file) {
            if (! in_array((string) $file, $known, true)) {
                app_alert_error(___('Language file not exists'));

                return back();
            }
        }

        // LANG-PIPELINE: snapshot the chrome strings BEFORE the save so the hook
        // below can tell which keys the human actually changed. Only the `lang`
        // file carries pipeline provenance — the rest are framework strings.
        $before = trans('lang', [], $code);
        $before = is_array($before) ? $before : [];

        foreach ($files as $file => $strings) {
            File::put($this->folder($code).'/'.$file.'.php', "<?php\n\nreturn ".var_export($strings, true).";\n");
        }

        // LANG-PIPELINE hook: human provenance for every chrome string whose value
        // the editor changed. markHuman is final + idempotent; unchanged keys are
        // left alone so placeholders are never mislabelled as human.
        $submitted = $files['lang'] ?? null;
        if (is_array($submitted)) {
            $pipeline = $this->pipelineService();
            foreach ($submitted as $key => $value) {
                if (is_array($value)) {
                    continue; // the pipeline tracks flat chrome keys only
                }
                $value = (string) $value;
                if ($value !== '' && ($before[$key] ?? null) !== $value) {
                    $pipeline->markHuman($code, (string) $key, $value);
                }
            }
        }

        app_alert_success(___('Updated Successfully'));

        return back();
    }

    private function defaultCode(): string
    {
        return (string) config('settings.lang', config('app.default_language'));
    }

    private function folder(string $code): string
    {
        return base_path('lang/'.$code);
    }

    /** Codes that are safe as a folder name under lang/ (no separators, no dots). */
    private function isFolderName(string $code): bool
    {
        return preg_match(LanguageRequest::CODE_PATTERN, $code) === 1;
    }

    /**
     * Names (without .php) of the strings files in a language folder. Other
     * files there (e.g. the pipeline's meta.json) are not translation files.
     *
     * @return list<string>
     */
    private function stringFiles(string $code): array
    {
        $names = [];
        foreach (File::files($this->folder($code)) as $file) {
            if ($file->getExtension() === 'php') {
                $names[] = $file->getFilenameWithoutExtension();
            }
        }
        sort($names);

        return $names;
    }

    /** Copy the default language's files into a new language folder (existing files are kept). */
    private function seedFolder(string $code): void
    {
        $target = $this->folder($code);
        if (File::isDirectory($target)) {
            return;
        }

        File::makeDirectory($target);
        foreach (File::allFiles($this->folder($this->defaultCode())) as $file) {
            $copy = $target.'/'.$file->getFilename();
            if (! File::exists($copy)) {
                File::copy($file->getPathname(), $copy);
            }
        }
    }

    private function deleteForm(Language $language): string
    {
        return '<form class="ui-inline" action="'.e(route('admin.languages.destroy', $language->id)).'" method="POST"'
            .' data-ui-confirm="'.e(___('Are you sure?')).'" data-ui-danger>'
            .method_field('DELETE').csrf_field()
            .'<button type="submit" class="ui-icon-btn ui-icon-btn--ghost ui-text-danger" title="'.e(___('Delete')).'" aria-label="'.e(___('Delete')).'">'
            .Cells::icon('trash').'</button>'
            .'</form>';
    }

    /**
     * LANG-PIPELINE: resolve the chrome-translate service. Stub-bindable seam —
     * the container cannot auto-build the ctor's `string $basePath`, so we resolve
     * a bound instance when the tests (or a provider) supply one, else forApp()
     * over base_path('lang'). (LEDGER Q2.)
     */
    private function pipelineService(): LangPipelineService
    {
        return app()->bound(LangPipelineService::class)
            ? app(LangPipelineService::class)
            : LangPipelineService::forApp();
    }

    /** The platform source locale — never a machine-translate target. */
    private function sourceLocale(): string
    {
        return config('settings.lang', config('app.default_language', 'en'));
    }

    /**
     * LANG-PIPELINE: per-locale pipeline page — coverage strip, searchable key
     * browser (?q + ?status), review queue, and the auto-translate control.
     *
     * A GET is safe: statuses are DERIVED live (statusFor's heuristic —
     * placeholder/absent/human need no sidecar), so the page never writes to
     * disk. The meta.json sidecar materializes lazily on the first real status
     * change (applyMachine / markHuman / approve / reject). (F0 deviation from
     * Q3's page-load bootstrap: a write-on-GET polluted the real lang tree during
     * the route smoke sweep and broke the legacy translate() view.)
     */
    public function pipeline(Request $request, string $code): View
    {
        $language = Language::where('code', $code)->firstOrFail();
        $svc = $this->pipelineService();
        $source = $this->sourceLocale();
        $isSource = ($code === $source);

        // Status filter — whitelist to the seam's STATUSES, ignore anything else.
        $status = $request->query('status');
        $status = (is_string($status) && in_array($status, LangPipelineService::STATUSES, true)) ? $status : null;

        $q = $request->query('q');
        $q = (is_string($q) && trim($q) !== '') ? trim($q) : null;

        $coverage = $svc->coverage();
        $cov = collect($coverage['locales'])->firstWhere('code', $code) ?? [
            'code' => $code, 'direction' => $language->direction, 'total' => 0,
            'human' => 0, 'machine' => 0, 'review' => 0, 'placeholder' => 0, 'absent' => 0, 'pct_done' => 0.0,
        ];

        return view('admin.languages.pipeline', [
            'language' => $language,
            'source' => $source,
            'isSource' => $isSource,
            'enTotal' => (int) $coverage['en_total'],
            'cov' => $cov,
            'statuses' => LangPipelineService::STATUSES,
            'q' => $q,
            'status' => $status,
            'rows' => $svc->keys($code, $q, $status),
            'review' => $svc->reviewQueue($code),
        ]);
    }

    /**
     * LANG-PIPELINE: JSON progress for the chrome-translate job (2s poll target).
     * Mirrors the menu ai-translate progress endpoint: the Cache payload or an
     * `idle` sentinel.
     */
    public function pipelineProgress(string $code): JsonResponse
    {
        $progress = Cache::get(LangPipelineService::progressKey($code));

        return response()->json($progress ?: ['status' => 'idle']);
    }

    /**
     * LANG-PIPELINE: kick off a machine-translate run for one locale. Guards:
     * unknown code → 404 (firstOrFail); source locale or inactive language →
     * redirect back with an error (never spends AI budget). On success seeds a
     * `queued` progress payload then dispatches the job reliably.
     */
    public function pipelineTranslate(Request $request, string $code): RedirectResponse
    {
        $language = Language::where('code', $code)->firstOrFail();

        if ($code === $this->sourceLocale()) {
            app_alert_error(___('The source language is never machine-translated.'));

            return back();
        }

        if (! $language->active) {
            app_alert_error(___('Enable this language before running auto-translate.'));

            return back();
        }

        // Seed progress so the poller shows "queued" immediately (menu precedent).
        Cache::put(LangPipelineService::progressKey($code), [
            'status' => 'queued', 'done' => 0, 'total' => 0, 'written' => 0, 'review' => 0, 'message' => null,
        ], 3600);

        dispatch_job_reliably(new LangChromeTranslateJob($code));

        app_alert_success(___('Auto-translate started. Progress updates below.'));

        return back();
    }

    /**
     * LANG-PIPELINE: approve a machine candidate (writes it to lang.php, sidecar
     * → machine). Unknown code → 404; missing key → redirect back with error.
     */
    public function pipelineApprove(Request $request, string $code): RedirectResponse
    {
        Language::where('code', $code)->firstOrFail();
        $key = (string) $request->input('key', '');
        if ($key === '') {
            app_alert_error(___('No candidate selected.'));

            return back();
        }

        $this->pipelineService()->approveCandidate($code, $key);
        app_alert_success(___('Approved'));

        return back();
    }

    /**
     * LANG-PIPELINE: reject a machine candidate (drops it, sidecar → placeholder
     * so it is re-translatable). Unknown code → 404; missing key → redirect back.
     */
    public function pipelineReject(Request $request, string $code): RedirectResponse
    {
        Language::where('code', $code)->firstOrFail();
        $key = (string) $request->input('key', '');
        if ($key === '') {
            app_alert_error(___('No candidate selected.'));

            return back();
        }

        $this->pipelineService()->rejectCandidate($code, $key);
        app_alert_success(___('Rejected'));

        return back();
    }

    /** HPMS E13: keys present in en but absent/empty in the target locale. */
    public function missingStrings(string $code): \Illuminate\View\View
    {
        $language = Language::where('code', $code)->firstOrFail();
        $default = config('settings.lang', config('app.default_language', 'en'));
        $missing = LanguagePackService::forApp()->missing($code, $default);

        return view('admin.languages.missing', compact('language', 'missing', 'default'));
    }

    /** HPMS E13: export a locale's strings as a downloadable JSON pack. */
    public function exportPack(string $code): JsonResponse
    {
        Language::where('code', $code)->firstOrFail();
        $map = LanguagePackService::forApp()->export($code);

        return response()->json($map, 200, [
            'Content-Disposition' => 'attachment; filename="lang-'.$code.'.json"',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /** HPMS E13: import a JSON language pack (direct file write, NEVER via ___()). */
    public function importPack(Request $request, string $code): RedirectResponse
    {
        Language::where('code', $code)->firstOrFail();
        $request->validate(['pack' => ['required', 'file', 'max:4096']]);

        $json = File::get($request->file('pack')->getRealPath());
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            app_alert_error(___('The pack must be a JSON object of key → string.'));

            return back();
        }

        $count = LanguagePackService::forApp()->import($code, $decoded);
        app_alert_success(___('Imported').' '.$count);

        return back();
    }
}
