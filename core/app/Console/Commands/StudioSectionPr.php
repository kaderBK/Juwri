<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use Juwri\Modules\Storefront\Models\AiSectionDraft;
use App\Models\ThemeVersion;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\Candidate;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\CssGate;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\SectionGates;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Theme Studio phase E1 step 4: turn an APPROVED AI section draft into a
 * library PR. Run from a developer checkout — never from the web, never on
 * production (the web only marks a draft approved).
 *
 *   php artisan studio:section-pr {id} [--library=1] [--dry-run]
 *
 * Steps:
 *  1. refuse unless the draft is approved, the target library does not ship
 *     the type yet, and (without --dry-run) the git working tree is clean
 *  2. re-run the automatic gates on the stored draft (defence in depth)
 *  3. write core/resources/liquid-library/<v>/sections/<type>.liquid, the CSS
 *     source tools/liquid-library/src/sections/<type>.css and the strings
 *     locales/{en,ar}/ai-<type>.json
 *  4. rebuild assets/liquid-library/<v>/library.css (node build.mjs) and run
 *     `studio:library-check --library=<v>`
 *  5. (not --dry-run) branch studio/ai-section-<type>, commit, push, `gh pr create`
 *
 * LIBRARY VERSIONING RULE. A library version is pinned by the themes
 * released against it (spec B0.1), so a published version must never change
 * under a third-party theme. Library 1 is not pinned by any published
 * third-party theme yet, so new sections are added to it directly. Once a
 * version is FROZEN — a `FROZEN` marker file in its directory, or a PUBLISHED
 * version of a third-party (seller) marketplace theme whose manifest declares
 * `"library": "<v>"` — this command writes into the next minor
 * version instead (1 → 1.1, 1.1 → 1.2), creating it as a copy of the frozen
 * one, and themes opt in by re-releasing against it.
 *
 * --tools and the `platform.liquid_library.path` config are for tests (a
 * temp library + CSS source tree); with either, the draft CSS is validated
 * with the PHP CSS gate instead of rebuilding library.css, so a test never
 * rewrites the real stylesheet.
 *
 * Caveat: tools/liquid-library/src is shared by every library version, so a
 * section written into a new minor version also adds its CSS source there;
 * rebuilding the frozen version afterwards needs build.mjs --src pointed at
 * a snapshot of its sources.
 */
class StudioSectionPr extends Command
{
    protected $signature = 'studio:section-pr
        {id : AiSectionDraft id (must be approved)}
        {--library=1 : Target section library version}
        {--dry-run : Write + build + check, but skip git and gh}
        {--tools= : Library tools dir (default: <repo>/tools/liquid-library)}';

    protected $description = 'Write an approved AI section draft into the section library and open a PR';

    public function handle(): int
    {
        $draft = AiSectionDraft::find((int) $this->argument('id'));
        if (! $draft) {
            $this->error('No AI section draft #'.$this->argument('id').'.');

            return self::FAILURE;
        }
        if ($draft->status !== AiSectionDraft::STATUS_APPROVED) {
            $this->error("Draft #{$draft->id} is {$draft->status} — only an approved draft can become a PR.");

            return self::FAILURE;
        }

        [$candidate, $problems] = Candidate::fromAnswer($draft->answer());
        if (! $candidate || $problems !== []) {
            $this->error('The stored draft is not a valid section: '.implode(' ', $problems));

            return self::FAILURE;
        }
        $type = $candidate->type;
        $dry = (bool) $this->option('dry-run');
        $tools = rtrim((string) ($this->option('tools') ?: base_path('../tools/liquid-library')), '/');
        $customPaths = $this->option('tools') !== null || config('platform.liquid_library.path');

        // 1. Target version (versioning rule) + refusals.
        $requested = SectionLibrary::forVersion((string) $this->option('library'));
        if (! $requested) {
            $this->error('Unknown section library version: '.$this->option('library'));

            return self::FAILURE;
        }
        $version = $this->targetVersion($requested);
        if ($version !== $requested->version) {
            $this->warn("Library {$requested->version} is frozen — writing into library {$version}.");
        }
        foreach (array_unique([$requested->version, $version]) as $v) {
            $lib = SectionLibrary::forVersion($v);
            if ($lib && $lib->sectionSource($type) !== null) {
                $this->error("Section type \"{$type}\" already exists in library {$v} — refusing to overwrite it.");

                return self::FAILURE;
            }
        }
        if (is_file($tools.'/src/sections/'.$type.'.css')) {
            $this->error("tools/liquid-library/src/sections/{$type}.css already exists — refusing to overwrite it.");

            return self::FAILURE;
        }
        if (! $dry && ($dirty = $this->git(['status', '--porcelain'])) !== '') {
            $this->error("The git working tree is dirty — commit or stash first:\n".$dirty);

            return self::FAILURE;
        }

        // 2. Re-gate the stored draft (no browser: the review already has the screenshots).
        $library = $requested;
        $report = new SectionGates($library)->run($draft->answer(), $draft->verticals ?? ['all']);
        if (! $report['passed']) {
            foreach (SectionGates::failures($report) as $f) {
                $this->error('  '.$f);
            }
            $this->error('The draft no longer passes the gates — nothing written.');

            return self::FAILURE;
        }

        // 3. Write the files.
        $libDir = $this->ensureVersionDir($requested, $version);
        $written = [
            $libDir.'/sections/'.$type.'.liquid' => $candidate->source(),
            $tools.'/src/sections/'.$type.'.css' => rtrim($candidate->css)."\n",
        ];
        foreach (['en', 'ar'] as $lang) {
            $written[$libDir.'/locales/'.$lang.'/ai-'.$type.'.json'] = json_encode(
                $candidate->locales[$lang] ?: new \stdClass,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )."\n";
        }
        foreach ($written as $path => $contents) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $contents);
            $this->line('  wrote '.$this->relative($path));
        }

        // 4. Build + library check.
        if ($customPaths) {
            $css = (new CssGate)->check($type, $candidate->css);
            if ($css['errors'] !== []) {
                return $this->abort($written, 'CSS gate: '.implode(' ', $css['errors']));
            }
            $this->line('  library.css rebuild skipped (custom library/tools path) — CSS validated with the PHP gate.');
        } else {
            $build = $this->node([base_path('../tools/liquid-library/build.mjs'), '--library='.$version]);
            if ($build === null) {
                return $this->abort($written, 'node is required to rebuild library.css (tools/liquid-library/build.mjs).');
            }
            if (! $build->isSuccessful()) {
                return $this->abort($written, 'library.css build failed: '.trim($build->getErrorOutput().$build->getOutput()));
            }
            $this->line('  '.trim($build->getOutput()));
            $written[base_path('../assets/liquid-library/'.$version.'/library.css')] = null;
        }

        $check = $this->runCommand('studio:library-check', ['--library' => $version], $buffer = new BufferedOutput);
        $output = trim($buffer->fetch());
        if ($check !== self::SUCCESS) {
            $this->line($output);

            return $this->abort($written, 'studio:library-check failed.');
        }
        $this->info('  studio:library-check passed for library '.$version.'.');

        if ($dry) {
            $this->info("Dry run: files written for \"{$type}\" in library {$version}; git and gh skipped.");

            return self::SUCCESS;
        }

        // 5. Branch, commit, push, PR.
        $branch = 'studio/ai-section-'.$type;
        $paths = array_map(fn ($p) => $this->relative($p), array_keys($written));
        $message = "feat(theme-studio): AI section {$type} (library {$version})\n\n"
            ."Authored by the AI section author (draft #{$draft->id}), passed every automatic gate\n"
            ."after {$draft->repair_rounds} repair round(s), approved by admin #{$draft->reviewed_by}.";
        foreach ([['checkout', '-b', $branch], ['add', '--', ...$paths], ['commit', '-m', $message], ['push', '-u', 'origin', $branch]] as $args) {
            $proc = new Process(['git', ...$args], base_path('..'), null, null, 120);
            $proc->run();
            if (! $proc->isSuccessful()) {
                $this->error('git '.$args[0].' failed: '.trim($proc->getErrorOutput()));

                return self::FAILURE;
            }
        }

        $body = "## AI-authored library section `{$type}`\n\n"
            .'- Request: '.str_replace("\n", ' ', mb_substr($draft->prompt, 0, 500))."\n"
            .'- Verticals: '.implode(', ', $draft->verticals ?? ['all'])."\n"
            ."- Gates: all passed (repair rounds: {$draft->repair_rounds}); review draft #{$draft->id}\n"
            .'- Review notes: '.($draft->review_notes ?: '—')."\n\n"
            ."Checks run by `studio:section-pr`: gate re-run, library.css build, `studio:library-check --library={$version}`.\n";
        $gh = new Process(['gh', 'pr', 'create', '--base', 'main', '--head', $branch, '--title', "feat(theme-studio): AI section {$type}", '--body', $body], base_path('..'), null, null, 120);
        $gh->run();
        if (! $gh->isSuccessful()) {
            $this->error('gh pr create failed: '.trim($gh->getErrorOutput()));

            return self::FAILURE;
        }
        $url = trim($gh->getOutput());
        $draft->forceFill(['pr_url' => mb_substr($url, 0, 300)])->save();
        $this->info('Opened '.$url);

        return self::SUCCESS;
    }

    /** The version to write into: the requested one, or its next minor when frozen. */
    protected function targetVersion(SectionLibrary $library): string
    {
        $version = $library->version;
        while ($this->frozen($version)) {
            $parts = explode('.', $version);
            $version = count($parts) === 1 ? $version.'.1' : implode('.', array_slice($parts, 0, -1)).'.'.((int) end($parts) + 1);
        }

        return $version;
    }

    /**
     * Frozen = a FROZEN marker in the library dir, or a published third-party
     * (seller) theme version pinned to it.
     */
    protected function frozen(string $version): bool
    {
        if (is_file(SectionLibrary::basePath().'/'.$version.'/FROZEN')) {
            return true;
        }
        // A PUBLISHED version of a third-party (seller) theme pinned to this
        // library version freezes it. Files on disk are not evidence: a local
        // checkout (or a parallel test) may hold uploads that were never
        // published, which must not push new sections into a new minor.
        try {
            return ThemeVersion::query()
                ->where('status', 'published')
                ->whereHas('theme', fn ($q) => $q->whereNotNull('seller_id'))
                ->get(['manifest'])
                ->contains(fn ($v) => (string) data_get($v->manifest, 'library', '') === $version);
        } catch (\Throwable $e) {
            report($e); // no ledger reachable → only the FROZEN marker counts

            return false;
        }
    }

    /** The target version dir; a new minor starts as a copy of the frozen version. */
    protected function ensureVersionDir(SectionLibrary $from, string $version): string
    {
        $dir = SectionLibrary::basePath().'/'.$version;
        if (! is_dir($dir)) {
            File::copyDirectory($from->path(), $dir);
            @unlink($dir.'/FROZEN');
            $this->line("  created library {$version} from {$from->version}");
        }

        return $dir;
    }

    /** @param  array<string,?string>  $written */
    protected function abort(array $written, string $why): int
    {
        foreach (array_keys($written) as $path) {
            if (! str_ends_with($path, 'library.css')) {
                @unlink($path);
            }
        }
        $this->error($why.' The written files were removed.');

        return self::FAILURE;
    }

    /** @param  list<string>  $args */
    protected function git(array $args): string
    {
        $proc = new Process(['git', ...$args], base_path('..'), null, null, 60);
        $proc->run();

        return trim($proc->getOutput());
    }

    /** @param  list<string>  $args */
    protected function node(array $args): ?Process
    {
        $node = (new ExecutableFinder)->find('node', null, ['/usr/local/bin', '/opt/homebrew/bin', '/usr/bin']);
        if (! $node) {
            return null;
        }
        $proc = new Process([$node, ...$args], base_path('..'), null, null, 120);
        $proc->run();

        return $proc;
    }

    protected function relative(string $path): string
    {
        $root = rtrim((string) realpath(base_path('..')), '/').'/';
        $real = (string) (realpath($path) ?: $path);

        return str_starts_with($real, $root) ? substr($real, strlen($root)) : $path;
    }
}
