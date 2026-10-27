<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Http\Controllers\User;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Modules\Pos\Models\SignageScreen;

/**
 * GAPS-RUN-2 B6-P2: owner signage config — create screens, view the public URL +
 * QR to open on a TV, regenerate token (revokes the old board), deactivate.
 * Tenant scoped; behind the digital_signage kill-switch.
 */
class SignageAdminController extends Controller
{
    use ResolvesCurrentStore;

    private function ownedScreen(int $id, array $ids): SignageScreen
    {
        return SignageScreen::whereIn('store_id', $ids)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $screens = SignageScreen::where('store_id', $rid)->latest()->get();

        return view('owner.signage.index', [
            'rid' => $rid,
            'screens' => $screens->map(fn ($s) => [
                'model' => $s,
                'url' => route('signage.board', $s->token),
                // PARITY-RUN T10: rendered by QrRenderService with the store's design.
                'qr' => branded_qr_svg($rid, route('signage.board', $s->token)),
            ]),
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function store(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $data = $request->validate([
            'kind' => ['required', 'in:menu,pickup'],
            'name' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $rid)], // P6-59b
        ]);

        SignageScreen::create([
            'store_id' => $rid,
            'branch_id' => $data['branch_id'] ?? null,
            'kind' => $data['kind'],
            'name' => $data['name'] ?? null,
            'token' => SignageScreen::newToken(),
            'active' => true,
        ]);

        return back()->with('success', ___('Screen created.'));
    }

    public function regenerate(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $screen = $this->ownedScreen($id, $this->storeIds());
        $screen->update(['token' => SignageScreen::newToken()]);

        return back()->with('success', ___('Token regenerated — the old URL no longer works.'));
    }

    public function toggle(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $screen = $this->ownedScreen($id, $this->storeIds());
        $screen->update(['active' => ! $screen->active]);

        return back()->with('success', ___('Screen updated.'));
    }

    public function destroy(Request $request, int $id)
    {
        $this->currentRestaurantId($request);
        $this->ownedScreen($id, $this->storeIds())->delete();

        return back()->with('success', ___('Screen deleted.'));
    }

    /** Inline SVG QR for a URL (BaconQrCode — already a dependency). */
}
