<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers\Admin;

use Juwri\Modules\Content\Http\Requests\Admin\TestimonialRequest;
use Juwri\Modules\Content\Models\Testimonial;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Admin\Concerns\KeepsPanelImage;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Requests\Admin\BulkDeleteRequest;
use Juwri\Core\Support\Admin\Cells;
use Juwri\Core\Support\Admin\DataTable;

/** Admin › Testimonials: customer quotes shown on the marketing site. */
class TestimonialController extends Controller
{
    use AnswersAdminPanel;
    use KeepsPanelImage;

    private const FOLDER = 'storage/testimonials/';

    private const PLACEHOLDER = 'default_user.png';

    public function __construct()
    {
        $this->authorizeResource(Testimonial::class, 'testimonial');
    }

    public function index(Request $request): View|JsonResponse
    {
        if (! $request->ajax()) {
            return view('admin.testimonials.index');
        }

        return DataTable::make($request, [0 => 'id', 1 => 'name', 2 => 'created_at'], ['name'])
            ->respond(Testimonial::query(), fn (Testimonial $t) => [
                Cells::person(asset(self::FOLDER.$t->image), $t->name, $t->designation),
                Cells::text($t->content),
                Cells::editButton(route('admin.testimonials.edit', $t->id)),
                Cells::checkbox($t->id),
            ]);
    }

    public function create(): View
    {
        return view('admin.testimonials.create');
    }

    public function store(TestimonialRequest $request): JsonResponse
    {
        Testimonial::create([
            ...$request->safe()->only(['name', 'designation', 'content']),
            'translations' => $request->validated('translations'),
            'image' => $this->panelImage($request->file('image'), self::FOLDER, '110x110', null, self::PLACEHOLDER),
        ]);

        return $this->panelDone(___('Created Successfully'));
    }

    /** There is no detail page; go back to the list. */
    public function show(Testimonial $testimonial): RedirectResponse
    {
        return redirect()->route('admin.testimonials.index');
    }

    public function edit(Testimonial $testimonial): View
    {
        return view('admin.testimonials.edit', ['testimonial' => $testimonial]);
    }

    public function update(TestimonialRequest $request, Testimonial $testimonial): JsonResponse
    {
        $testimonial->update([
            ...$request->safe()->only(['name', 'designation', 'content']),
            'translations' => $request->validated('translations'),
            'image' => $this->panelImage($request->file('image'), self::FOLDER, '110x110', $testimonial->image, self::PLACEHOLDER),
        ]);

        return $this->panelDone(___('Updated Successfully'));
    }

    public function destroy(Testimonial $testimonial): RedirectResponse
    {
        $this->dropPanelImage($testimonial->image, self::FOLDER, self::PLACEHOLDER);
        $testimonial->delete();

        return back()->with('success', ___('Deleted Successfully'));
    }

    /** Bulk delete from the list screen; uploaded pictures go too. */
    public function delete(BulkDeleteRequest $request): JsonResponse
    {
        $this->authorize('deleteAny', Testimonial::class);

        foreach (Testimonial::query()->whereKey($request->ids())->get() as $testimonial) {
            $this->dropPanelImage($testimonial->image, self::FOLDER, self::PLACEHOLDER);
            $testimonial->delete();
        }

        return $this->panelDone(___('Deleted Successfully'));
    }
}
