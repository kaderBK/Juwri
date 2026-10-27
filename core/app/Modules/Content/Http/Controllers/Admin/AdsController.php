<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdSlotRequest;
use Juwri\Modules\Content\Models\Adsense;
use Juwri\Core\Support\Admin\Cells;
use Juwri\Core\Support\Admin\DataTable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin › Advertisements: the fixed ad slots of the public site. Slots are
 * seeded, so they can only be edited; the `head_code` slot (a snippet for
 * <head>) is edited from its own button, not listed with the others.
 */
class AdsController extends Controller
{
    use AnswersAdminPanel;

    private const HEAD_SLOT = 'head_code';

    public function __construct()
    {
        $this->authorizeResource(Adsense::class, 'advertisement');
    }

    public function index(Request $request): View|JsonResponse
    {
        if (! $request->ajax()) {
            return view('admin.ads.index', [
                'headAd' => Adsense::query()->where('slug', self::HEAD_SLOT)->first(),
            ]);
        }

        $slots = Adsense::query()->where('slug', '!=', self::HEAD_SLOT);

        return DataTable::make($request, [0 => 'id'], ['slug', 'provider_name'])
            ->respond($slots, fn (Adsense $slot) => [
                Cells::text($slot->slug),
                Cells::text($slot->provider_name),
                Cells::status((bool) $slot->status),
                Cells::editButton(route('admin.advertisements.edit', $slot->id)),
            ]);
    }

    public function edit(Adsense $advertisement): View
    {
        return view('admin.ads.edit', ['advertisement' => $advertisement]);
    }

    public function update(AdSlotRequest $request, Adsense $advertisement): JsonResponse
    {
        $advertisement->update([
            'provider_name' => $request->validated('provider_name'),
            'status' => $request->boolean('status') ? 1 : 0,
            'code' => $request->validated('code'),
        ]);

        return $this->panelDone(___('Updated Successfully'));
    }

    /** Slots are fixed: there is nothing to create or show on its own. */
    public function create(): RedirectResponse
    {
        return redirect()->route('admin.advertisements.index');
    }

    public function store(Request $request): RedirectResponse
    {
        return redirect()->route('admin.advertisements.index');
    }

    public function show(Adsense $advertisement): RedirectResponse
    {
        return redirect()->route('admin.advertisements.index');
    }

    public function destroy(Adsense $advertisement): RedirectResponse
    {
        $advertisement->delete();

        return back()->with('success', ___('Deleted Successfully'));
    }
}
