<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hr\Http\Controllers\User;

use App\Models\StaffMember;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Services\Tenancy\TenantStorage;
use Juwri\Modules\Hr\Models\StaffDocument;
use Juwri\Modules\Hr\Models\StaffOnboardingItem;
use Juwri\Modules\Hr\Models\StaffRoleRate;
use Juwri\Modules\Hr\Services\Hrm\HrmService;

/**
 * SYSTEMS-RUN H-P1: employee file — profile, documents vault, per-role wage
 * rates, onboarding checklist. Owner/manager scoped (staff belong to the owner).
 * Behind the hrm_suite kill-switch.
 */
class StaffHrmController extends Controller
{
    public function __construct(private HrmService $hrm) {}

    private function ownedStaff(int $id): StaffMember
    {
        return StaffMember::where('owner_id', auth()->id())->findOrFail($id);
    }

    public function show(Request $request, int $id)
    {
        $staff = $this->ownedStaff($id);
        $this->hrm->ensureOnboarding($staff);

        return view('owner.team.hrm-profile', [
            'staff' => $staff,
            'documents' => StaffDocument::where('staff_id', $staff->id)->latest()->get(),
            'rates' => StaffRoleRate::where('staff_id', $staff->id)->get(),
            'onboarding' => StaffOnboardingItem::where('staff_id', $staff->id)->orderBy('position')->get(),
            'progress' => $this->hrm->onboardingProgress($staff),
        ]);
    }

    public function updateProfile(Request $request, int $id)
    {
        $staff = $this->ownedStaff($id);
        $data = $request->validate([
            'national_id' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:120'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'employment_start' => ['nullable', 'date'],
            'contract_type' => ['nullable', 'in:full_time,part_time,casual'],
            'base_wage' => ['nullable', 'numeric', 'min:0'],
        ]);
        $staff->update($data);

        return back()->with('success', ___('Profile updated.'));
    }

    public function setRate(Request $request, int $id)
    {
        $staff = $this->ownedStaff($id);
        $data = $request->validate([
            'role' => ['required', 'in:manager,cashier,kitchen,waiter'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
        ]);
        StaffRoleRate::updateOrCreate(
            ['staff_id' => $staff->id, 'role' => $data['role']],
            ['hourly_rate' => $data['hourly_rate']]
        );

        return back()->with('success', ___('Wage rate saved.'));
    }

    public function uploadDocument(Request $request, int $id)
    {
        $staff = $this->ownedStaff($id);
        $data = $request->validate([
            'kind' => ['required', 'in:contract,id,health_cert,custom'],
            'label' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);
        // SECURITY: employee documents (IDs, contracts, health certs) must never sit
        // under storage/app/public — that is symlinked into the webroot and served
        // with no auth. Private 'local' disk + authenticated download route below.
        // P1-04 slice 7: new uploads under the account prefix (existing rows keep their stored path).
        $path = $request->file('file')->store(TenantStorage::dir('staff-docs/'.$staff->store_id, (int) $staff->account_id ?: null), 'local');
        StaffDocument::create([
            'store_id' => $staff->store_id,
            'staff_id' => $staff->id,
            'kind' => $data['kind'],
            'label' => $data['label'] ?? null,
            'path' => $path,
            'expires_at' => $data['expires_at'] ?? null,
            'uploaded_by' => auth()->id(),
            'uploaded_by_staff' => false,
        ]);

        return back()->with('success', ___('Document uploaded.'));
    }

    /**
     * SECURITY: stream an employee document to its owning tenant only. Anyone who
     * is not the staff member's owner gets a 404 (ownedStaff) — no public URL exists.
     */
    public function downloadDocument(Request $request, int $id, int $docId)
    {
        $staff = $this->ownedStaff($id);
        $doc = StaffDocument::where('staff_id', $staff->id)->findOrFail($docId);
        abort_unless($doc->path, 404);

        $abs = storage_path('app/'.$doc->path);
        // Legacy rows uploaded before the private-disk fix live under app/public.
        if (! is_file($abs)) {
            $abs = storage_path('app/public/'.$doc->path);
        }
        abort_unless(is_file($abs), 404);

        return response()->file($abs, ['Cache-Control' => 'private, no-store']);
    }

    public function destroyDocument(Request $request, int $id, int $docId)
    {
        $staff = $this->ownedStaff($id);
        $doc = StaffDocument::where('staff_id', $staff->id)->findOrFail($docId);
        foreach ([storage_path('app/'.$doc->path), storage_path('app/public/'.$doc->path)] as $abs) {
            if ($doc->path && is_file($abs)) {
                @unlink($abs);
            }
        }
        $doc->delete();

        return back()->with('success', ___('Document deleted.'));
    }

    public function toggleOnboarding(Request $request, int $id, int $itemId)
    {
        $staff = $this->ownedStaff($id);
        $item = StaffOnboardingItem::where('staff_id', $staff->id)->findOrFail($itemId);
        $item->update([
            'done' => ! $item->done,
            'done_by' => auth()->id(),
            'done_at' => ! $item->done ? now() : null,
        ]);

        return back()->with('success', ___('Checklist updated.'));
    }
}
