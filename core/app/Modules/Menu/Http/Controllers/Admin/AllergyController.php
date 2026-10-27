<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Admin\Concerns\KeepsPanelImage;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Requests\Admin\BulkDeleteRequest;
use Juwri\Core\Http\Requests\Admin\ReorderRequest;
use Juwri\Core\Support\Admin\Cells;
use Juwri\Core\Support\Admin\DataTable;
use Juwri\Modules\Menu\Http\Requests\Admin\AllergyRequest;
use Juwri\Modules\Menu\Models\Allergy;

/**
 * Admin › Allergens: the allergen catalogue restaurants pick from on their
 * menu items. The list shows every allergen row, including the ones a
 * restaurant added for itself, so the superadmin can moderate them.
 */
class AllergyController extends Controller
{
    use AnswersAdminPanel;
    use KeepsPanelImage;

    private const FOLDER = 'storage/allergies/';

    private const PLACEHOLDER = 'default.png';

    /** New allergens go to the end of the list until someone reorders it. */
    private const NEW_POSITION = 999;

    public function __construct()
    {
        $this->authorizeResource(Allergy::class, 'allergy');
    }

    public function index(Request $request): View|JsonResponse
    {
        if (! $request->ajax()) {
            return view('admin.allergies.index');
        }

        return DataTable::make($request, [0 => 'position', 1 => 'title', 2 => 'active'], ['title'])
            ->respond(Allergy::query(), fn (Allergy $allergy) => [
                Cells::reorderHandle($allergy->id),
                Cells::image($this->imageUrl($allergy->image), $allergy->title),
                Cells::status((string) $allergy->active === '1'),
                Cells::editButton(route('admin.allergies.edit', $allergy->id)),
                Cells::checkbox($allergy->id),
            ]);
    }

    public function create(): View
    {
        return view('admin.allergies.create');
    }

    public function store(AllergyRequest $request): JsonResponse
    {
        Allergy::create([
            'title' => $request->validated('title'),
            'active' => $request->boolean('active') ? '1' : '0',
            'translations' => $request->validated('translations'),
            'image' => $this->panelImage($request->file('image'), self::FOLDER, '32x32', null, self::PLACEHOLDER),
            'position' => self::NEW_POSITION,
        ]);

        return $this->panelDone(___('Created Successfully'));
    }

    public function show(Allergy $allergy): never
    {
        abort(404);
    }

    public function edit(Allergy $allergy): View
    {
        return view('admin.allergies.edit', ['allergy' => $allergy]);
    }

    public function update(AllergyRequest $request, Allergy $allergy): JsonResponse
    {
        $allergy->update([
            'title' => $request->validated('title'),
            'active' => $request->boolean('active') ? '1' : '0',
            'translations' => $request->validated('translations'),
            'image' => $this->panelImage($request->file('image'), self::FOLDER, '32x32', $allergy->image, self::PLACEHOLDER),
        ]);

        return $this->panelDone(___('Updated Successfully'));
    }

    public function destroy(Allergy $allergy): never
    {
        abort(404);
    }

    public function reorder(ReorderRequest $request): JsonResponse
    {
        $this->authorize('reorder', Allergy::class);

        $this->savePositions(Allergy::class, $request->order());

        return $this->panelDone(___('Updated Successfully'));
    }

    /** Bulk delete from the list screen; uploaded pictures go too. */
    public function delete(BulkDeleteRequest $request): JsonResponse
    {
        $this->authorize('deleteAny', Allergy::class);

        $allergies = Allergy::query()->whereKey($request->ids())->get();
        foreach ($allergies as $allergy) {
            $this->dropPanelImage($allergy->image, self::FOLDER, self::PLACEHOLDER);
            $allergy->delete();
        }

        return $this->panelDone(___('Deleted Successfully'));
    }

    private function imageUrl(?string $image): string
    {
        return Str::isUrl((string) $image) ? (string) $image : asset(self::FOLDER.$image);
    }
}
