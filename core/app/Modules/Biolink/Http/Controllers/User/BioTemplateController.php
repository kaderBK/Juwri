<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Biolink\Http\Controllers\User;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Contracts\AiLimitExceeded;
use Juwri\Modules\Ai\Contracts\AiProviderFailure;
use Juwri\Modules\Biolink\Jobs\TranslateBioBlocksJob;
use Juwri\Modules\Biolink\Services\Ai\BioGeneratorService;

/**
 * BIOLINK-TEMPLATES-RUN (Lane B) · the AI + translate endpoints for the bio
 * builder. A SEPARATE controller from BioLinkController so this run never edits
 * Lane C's file (fence-by-file); the routes share the same auth/tenant/demo stack
 * plus the `feature:ai_biolink` kill-switch (routes/features5/biolink.php).
 *
 * Gating is two-layer: `feature:ai_biolink` middleware = superadmin kill-switch;
 * this controller re-asks `plan_feature('ai_biolink', owner)` = the plan entitlement
 * (the features row stays kill-only, like the biolink master switch). Tenant
 * scoping is the shared {@see ResolvesCurrentStore} guard (403 off-tenant).
 * Every write verb is a POST, so the `demo` middleware blocks it on demo sites.
 *
 * Services resolve by STRING (`app(...)`) so tests can bind fakes — the AI seam
 * is a bound FakeAutopilotAi in the suite, never a live call.
 */
class BioTemplateController extends Controller
{
    use ResolvesCurrentStore;

    /**
     * Typed override of the trait's untyped `storeIds(): array` — PHPStan
     * analyses a trait method in the context of every using class, so a class
     * that does not narrow the value type inherits the "no value type" finding.
     * The ids are always ints; this states that honestly (no baseline entry).
     *
     * @return array<int, int>
     */
    protected function storeIds(): array
    {
        return dashboard_store_ids();
    }

    private function generator(): BioGeneratorService
    {
        return app(BioGeneratorService::class);
    }

    /* ─────────────────────────── T1-4 · AI generate ───────────────────────── */

    /**
     * Propose a bio page from the store's own data. Returns the proposal for the
     * owner to review; writes nothing (never auto-applies — SPEC T1-4).
     */
    public function generate(Request $request): JsonResponse
    {
        $store = $this->currentRestaurant($request);          // 403 off-tenant (IDOR)
        $owner = $this->requirePlan();

        try {
            $proposal = $this->generator()->generate($store->id, $owner->id);
        } catch (AiLimitExceeded $e) {
            return response()->json([
                'success' => false,
                'message' => ___('You have reached your AI usage limit for this month.'),
            ], 429);
        } catch (AiProviderFailure $e) {
            return response()->json([
                'success' => false,
                'message' => ___('AI is not available right now. Please try again in a moment.'),
            ], 503);
        }

        return response()->json([
            'success' => true,
            'proposal' => $proposal,
            'message' => ___('Here is your AI-drafted bio page. Review the blocks, then apply.'),
        ]);
    }

    /**
     * Apply a REVIEWED proposal — seeds its blocks onto the main page, honoring
     * the plan block limit and the hidden-when-missing rule. `replace=1` clears
     * the existing main-page blocks first.
     */
    public function applyGenerated(Request $request): JsonResponse
    {
        $store = $this->currentRestaurant($request);
        $owner = $this->requirePlan();

        $v = $request->validate([
            'proposal' => ['required', 'array'],
            'proposal.archetype' => ['nullable', 'string', 'max:64'],
            'proposal.preset_suggestion' => ['nullable', 'string', 'max:64'],
            'proposal.blocks' => ['required', 'array', 'min:1'],
            'replace' => ['nullable'],
        ]);

        $created = $this->generator()->apply(
            $store->id,
            $v['proposal'],
            $request->boolean('replace'),
            $owner->id
        );

        return response()->json([
            'success' => true,
            'created' => $created,
            'message' => trans_choice(
                '{0}No blocks were added.|{1}Added :count block from the AI draft.|[2,*]Added :count blocks from the AI draft.',
                $created,
                ['count' => $created]
            ),
        ]);
    }

    /* ─────────────────────────── T2-2 · AI translate ──────────────────────── */

    /**
     * Kick off a background "translate the whole bio page" run and return the
     * progress key the builder polls. The heavy work (one AI pass per non-base
     * locale) runs on the `heavy` queue — never inline in this request.
     */
    public function translate(Request $request): JsonResponse
    {
        $store = $this->currentRestaurant($request);          // 403 off-tenant (IDOR)
        $owner = $this->requirePlan();

        TranslateBioBlocksJob::dispatch($store->id, $owner->id);

        return response()->json([
            'success' => true,
            'progress_key' => TranslateBioBlocksJob::progressKey($store->id),
            'message' => ___('Translating your bio page in the background. This can take a minute.'),
        ]);
    }

    /** Poll the translate job's progress (status/done/total/locale). */
    public function translateProgress(Request $request): JsonResponse
    {
        $store = $this->currentRestaurant($request);
        $this->requirePlan();

        $progress = Cache::get(TranslateBioBlocksJob::progressKey($store->id), ['status' => 'idle']);

        return response()->json(['success' => true, 'progress' => $progress]);
    }

    /* ─────────────────────────── internals ────────────────────────────────── */

    /**
     * The owner (entitlement subject, never the staff member), gated on the
     * `ai_biolink` PLAN entitlement — the middleware only kill-switches, so the
     * plan gate lives here. 403 when the owner's plan does not sell the feature.
     */
    private function requirePlan(): User
    {
        $owner = $this->owner();
        abort_unless($owner instanceof User && plan_feature('ai_biolink', $owner), 403, ___('This feature is not included in your current plan.'));

        return $owner;
    }

    private function owner(): ?User
    {
        return function_exists('dashboard_owner') ? dashboard_owner() : auth()->user();
    }
}
