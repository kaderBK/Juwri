<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pharmacy\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Pharmacy\Models\PharmacyLicence;
use Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyMarkets;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * P1-23 (AUP 3.1(b)): the platform's review of pharmacy licence uploads. A pharmacy store is published only with an
 * APPROVED, unexpired licence in an enabled country ({@see PharmacyMarkets}); a rejection carries a note the owner
 * sees on their compliance page. Superadmin only (the admin route group).
 */
class PharmacyLicenceReviewController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', PharmacyLicence::PENDING);
        if (! in_array($status, [PharmacyLicence::PENDING, PharmacyLicence::APPROVED, PharmacyLicence::REJECTED, 'all'], true)) {
            $status = PharmacyLicence::PENDING;
        }

        $licences = PharmacyLicence::query()->with('store')
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderBy('id')
            ->paginate(25)->withQueryString();

        return view('admin.pharmacy-licences.index', [
            'licences' => $licences,
            'status' => $status,
            'enabledCountries' => PharmacyMarkets::enabledCountries(),
        ]);
    }

    public function document(int $id): StreamedResponse
    {
        $licence = PharmacyLicence::query()->findOrFail($id);
        $disk = Storage::disk((string) config('pharmacy.licence_disk', 'local'));
        abort_unless($disk->exists($licence->document_path), 404);

        return $disk->download($licence->document_path, 'licence-'.$licence->id.'.'.pathinfo($licence->document_path, PATHINFO_EXTENSION));
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        return $this->decide($request, $id, PharmacyLicence::APPROVED);
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $request->validate(['review_note' => ['required', 'string', 'max:500']]);

        return $this->decide($request, $id, PharmacyLicence::REJECTED);
    }

    private function decide(Request $request, int $id, string $status): RedirectResponse
    {
        $licence = PharmacyLicence::query()->findOrFail($id);
        $licence->forceFill([
            'status' => $status,
            'review_note' => $request->filled('review_note') ? mb_substr((string) $request->input('review_note'), 0, 500) : null,
            'reviewed_by' => (int) $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        log_activity('pharmacy.licence_'.$status, $licence, ['country' => $licence->country], (int) $licence->store_id);
        app_alert_success($status === PharmacyLicence::APPROVED ? ___('Licence approved.') : ___('Licence rejected.'));

        return redirect()->route('admin.pharmacy-licences.index');
    }
}
