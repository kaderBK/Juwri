<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Controllers\Admin;

use App\Models\Coupon;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Loyalty\Services\CouponBulk;
use Juwri\Modules\Loyalty\Services\PlatformCouponInput;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin › Membership › Coupons (C2): bulk generation (create → confirm → store),
 * batch history & result view, scoped bulk actions, and filtered CSV export.
 */
class CouponBulkController extends Controller
{
    use AnswersAdminPanel;

    /** @var array{scope: 'platform'} */
    private const SCOPE = ['scope' => 'platform'];

    public function bulkCreate(): View
    {
        $this->authorize('create', Coupon::class);

        return view('admin.billing.coupons.bulk-create');
    }

    public function bulkConfirm(Request $request, PlatformCouponInput $input): View
    {
        $this->authorize('create', Coupon::class);
        $data = $input->validateBulk($request->all());

        $sampleRandom = str_repeat('X', $data['length']);
        $sampleCode = ($data['prefix'] !== null ? $data['prefix'].'-' : '').$sampleRandom;

        return view('admin.billing.coupons.bulk-confirm', [
            'data' => $data,
            'sampleCode' => $sampleCode,
        ]);
    }

    public function bulkStore(Request $request, PlatformCouponInput $input, CouponBulk $bulk): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Coupon::class);
        $data = $input->validateBulk($request->all());

        $batch = $bulk->generate(
            self::SCOPE,
            $data['settings'],
            $data['count'],
            $data['prefix'],
            $data['length'],
            $data['name'],
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'batch_id' => $batch->id,
                'count' => $batch->count,
                'message' => ___('Coupon batch generated successfully.'),
            ]);
        }

        return redirect()
            ->route('admin.coupons.batches.show', $batch->id)
            ->with('success', ___('Coupon batch generated successfully.'));
    }

    public function batches(CouponBulk $bulk): View
    {
        $this->authorize('viewAny', Coupon::class);

        $batches = $bulk->batches(self::SCOPE)
            ->withCount('coupons')
            ->latest('id')
            ->paginate(25);

        return view('admin.billing.coupons.batches', [
            'batches' => $batches,
        ]);
    }

    public function batchResult(int $batch, CouponBulk $bulk): View
    {
        $this->authorize('viewAny', Coupon::class);

        $batchModel = $bulk->batches(self::SCOPE)->findOrFail($batch);
        $coupons = $bulk->query(self::SCOPE, ['batch_id' => $batchModel->id])
            ->orderBy('coupons.id')
            ->paginate(50);

        return view('admin.billing.coupons.batch-result', [
            'batch' => $batchModel,
            'coupons' => $coupons,
            'bulk' => $bulk,
        ]);
    }

    public function bulkAction(Request $request, CouponBulk $bulk): JsonResponse|RedirectResponse
    {
        $validated = Validator::make($request->all(), [
            'action' => ['required', Rule::in(['delete_unused', 'expire_now', 'extend_expiry', 'set_limit'])],
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer', 'min:1'],
            'apply_to_filtered' => ['nullable', 'boolean'],
            'extend_to' => ['required_if:action,extend_expiry', 'nullable', 'date', 'after:now'],
            'limit' => ['required_if:action,set_limit', 'nullable', 'integer', 'min:0'],
        ], [
            'extend_to.after' => ___('The new expiry must be in the future.'),
        ])->validate();

        $action = (string) $validated['action'];
        if ($action === 'delete_unused') {
            $this->authorize('deleteAny', Coupon::class);
        } else {
            $this->authorize('update', new Coupon);
        }

        $ids = array_values(array_unique(array_map('intval', $validated['ids'] ?? [])));
        $applyFiltered = ! empty($validated['apply_to_filtered']);

        if ($ids === [] && ! $applyFiltered) {
            $message = ___('Select at least one coupon or apply to the filtered list.');

            return $request->expectsJson()
                ? response()->json(['success' => false, 'message' => $message], 422)
                : back()->with('error', $message);
        }

        $query = $bulk->query(self::SCOPE, $this->filtersFrom($request));
        Hooks::call('admin.coupons.query', $query);

        if ($ids !== []) {
            $query->whereIn('coupons.id', $ids);
        }

        $context = ['scope' => 'platform'];
        $message = match ($action) {
            'delete_unused' => $this->formatDeleteUnused($bulk->deleteUnused($query, $context)),
            'expire_now' => ___('Expired :count coupons.', ['count' => $bulk->expireNow($query, $context)]),
            'extend_expiry' => ___('Extended expiry for :count coupons.', [
                'count' => $bulk->extendExpiry($query, Carbon::parse((string) $validated['extend_to']), $context),
            ]),
            'set_limit' => $this->formatSetLimit($bulk->setLimit($query, (int) $validated['limit'], $context)),
            default => abort(422),
        };

        if ($request->expectsJson()) {
            return $this->panelDone($message);
        }

        return back()->with('success', $message);
    }

    public function export(Request $request, CouponBulk $bulk): StreamedResponse
    {
        $this->authorize('viewAny', Coupon::class);

        $query = $bulk->query(self::SCOPE, $this->filtersFrom($request));
        Hooks::call('admin.coupons.query', $query);

        return $bulk->exportCsv($query, 'platform-coupons.csv', ['scope' => 'platform']);
    }

    /** @return array<string, mixed> */
    private function filtersFrom(Request $request): array
    {
        $filters = [];
        foreach (['status', 'batch_id', 'type', 'applies_interval', 'created_from', 'created_to', 'search'] as $key) {
            $val = $request->input($key);
            if (is_scalar($val) && trim((string) $val) !== '') {
                $filters[$key] = $val;
            }
        }

        if (isset($filters['status']) && ! in_array($filters['status'], CouponBulk::STATUSES, true)) {
            unset($filters['status']);
        }

        return $filters;
    }

    /** @param  array{0: int, 1: int}  $result */
    private function formatDeleteUnused(array $result): string
    {
        return ___('Deleted :deleted unused coupons (:kept used kept).', [
            'deleted' => $result[0],
            'kept' => $result[1],
        ]);
    }

    /** @param  array{0: int, 1: int}  $result */
    private function formatSetLimit(array $result): string
    {
        return ___('Updated limit for :updated coupons (:skipped skipped).', [
            'updated' => $result[0],
            'skipped' => $result[1],
        ]);
    }
}
