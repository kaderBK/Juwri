<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Storefront\Models\AiSectionDraft;
use Juwri\Modules\Storefront\Services\ThemeStudio\AiSectionAuthor;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\Candidate;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\LineDiff;
use App\Services\Verticals\VerticalRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Theme Studio phase E1: the superadmin AI section author + review queue
 * (/admin/theme-studio/sections). Admin group (`admin` + `demo` +
 * `csp.dashboard`) + `feature:ai_section_author` (dark kill switch).
 *
 *   GET  /                      list + request form
 *   POST /                      generate → gates → repair rounds → draft (JSON {id, status, url})
 *   GET  /{draft}               detail: gate report, en/ar × mobile/desktop screenshots,
 *                               the files the PR would add, per-round diffs
 *   GET  /{draft}/shot/{key}    one screenshot (admin-only, from storage/app)
 *   POST /{draft}/approve       mark approved (+ notes) — writes NOTHING to the library
 *   POST /{draft}/reject        mark rejected (+ notes)
 *
 * Never writes production code: an approved draft reaches the library only
 * through `php artisan studio:section-pr {id}` in a developer checkout (a git
 * branch + PR reviewed like any code).
 */
class AiSectionAuthorController extends Controller
{
    public function index(): mixed
    {
        $drafts = AiSectionDraft::query()->latest('id')->limit(100)->get();

        return view('admin.theme_studio.sections.index', [
            'drafts' => $drafts,
            'verticals' => $this->verticals(),
            'config' => [
                'urls' => ['generate' => route('admin.section-author.store')],
                'csrf' => csrf_token(),
            ],
        ]);
    }

    /** POST — {request, verticals[], type?} → a gated draft. */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $data = validator($request->all(), [
            'request' => ['required', 'string', 'min:10', 'max:2000'],
            'verticals' => ['sometimes', 'array', 'max:12'],
            'verticals.*' => ['string', Rule::in(array_merge(['all'], array_keys($this->verticals())))],
            'type' => ['sometimes', 'nullable', 'string', 'min:3', 'max:40', 'regex:'.Candidate::TYPE_PATTERN],
        ])->validate();

        // Up to 4 model calls + gates. Web only: in the CLI (tests, queue workers) the
        // limit would cap the whole long-lived process, e.g. a parallel test worker.
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(600);
        }

        $verticals = array_values(array_unique((array) ($data['verticals'] ?? []))) ?: ['all'];
        try {
            $draft = app(AiSectionAuthor::class)->author((string) $data['request'], $verticals, [
                'type' => $data['type'] ?? null,
                'user_id' => (int) auth()->id(),
            ]);
        } catch (\Throwable $e) {
            report($e);
            $message = ___('The section author failed: :error', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : back()->withErrors(['request' => $message]);
        }

        $url = route('admin.section-author.show', $draft->id);

        return $request->expectsJson()
            ? response()->json(['id' => $draft->id, 'status' => $draft->status, 'type' => $draft->type, 'url' => $url])
            : redirect($url);
    }

    public function show(int $draft): mixed
    {
        $row = AiSectionDraft::findOrFail($draft);

        $finalSource = '';
        if ($row->type) {
            [$candidate] = Candidate::fromAnswer($row->answer());
            $finalSource = $candidate?->source() ?? (string) $row->liquid;
        }

        $rounds = [];
        $previous = null;
        foreach ($row->attempts ?? [] as $attempt) {
            $answer = is_array($attempt['answer'] ?? null) ? $attempt['answer'] : null;
            $rounds[] = [
                'attempt' => $attempt,
                'liquid_diff' => $previous && $answer ? LineDiff::lines((string) ($previous['liquid'] ?? ''), (string) ($answer['liquid'] ?? '')) : null,
                'css_diff' => $previous && $answer ? LineDiff::lines((string) ($previous['css'] ?? ''), (string) ($answer['css'] ?? '')) : null,
            ];
            $previous = $answer ?? $previous;
        }

        $shots = [];
        foreach (['en-mobile', 'ar-mobile', 'en-desktop', 'ar-desktop'] as $key) {
            if (isset($row->screenshots[$key])) {
                $shots[$key] = route('admin.section-author.shot', ['draft' => $row->id, 'key' => $key]);
            }
        }

        return view('admin.theme_studio.sections.show', [
            'draft' => $row,
            'rounds' => $rounds,
            'shots' => $shots,
            'files' => [
                "core/resources/liquid-library/{$row->library_version}/sections/{$row->type}.liquid" => LineDiff::lines('', $finalSource),
                "tools/liquid-library/src/sections/{$row->type}.css" => LineDiff::lines('', (string) $row->css),
            ],
            'command' => "php artisan studio:section-pr {$row->id} --library={$row->library_version}",
        ]);
    }

    public function screenshot(int $draft, string $key): BinaryFileResponse
    {
        $row = AiSectionDraft::findOrFail($draft);
        $rel = (string) ($row->screenshots[$key] ?? '');
        abort_if($rel === '' || ! str_starts_with($rel, $row->storageDir().'/') || str_contains($rel, '..'), 404);
        $path = storage_path('app/'.$rel);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=600']);
    }

    public function approve(Request $request, int $draft): JsonResponse|RedirectResponse
    {
        $row = AiSectionDraft::findOrFail($draft);
        $data = validator($request->all(), ['notes' => ['sometimes', 'nullable', 'string', 'max:2000']])->validate();

        if (! $row->reviewable()) {
            $message = ___('Only a draft that passed every gate can be approved.');

            return $request->expectsJson() ? response()->json(['message' => $message], 422) : back()->withErrors(['review' => $message]);
        }

        $row->forceFill([
            'status' => AiSectionDraft::STATUS_APPROVED,
            'reviewed_by' => (int) auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $data['notes'] ?? null,
        ])->save();

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'status' => $row->status])
            : redirect()->route('admin.section-author.show', $row->id)->with('success', ___('Approved. Open the PR from a developer checkout with the command shown.'));
    }

    public function reject(Request $request, int $draft): JsonResponse|RedirectResponse
    {
        $row = AiSectionDraft::findOrFail($draft);
        $data = validator($request->all(), ['notes' => ['required', 'string', 'min:3', 'max:2000']])->validate();

        if ($row->status === AiSectionDraft::STATUS_REJECTED || $row->status === AiSectionDraft::STATUS_GENERATING) {
            $message = ___('This draft cannot be rejected now.');

            return $request->expectsJson() ? response()->json(['message' => $message], 422) : back()->withErrors(['review' => $message]);
        }

        $row->forceFill([
            'status' => AiSectionDraft::STATUS_REJECTED,
            'reviewed_by' => (int) auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $data['notes'],
        ])->save();

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'status' => $row->status])
            : redirect()->route('admin.section-author.show', $row->id)->with('success', ___('Rejected.'));
    }

    /** @return array<string,string> vertical type => label */
    protected function verticals(): array
    {
        $out = [];
        foreach (app(VerticalRegistry::class)->all() as $type => $config) {
            $out[(string) $type] = ___((string) ($config['label'] ?? ucfirst((string) $type)));
        }

        return $out;
    }
}
