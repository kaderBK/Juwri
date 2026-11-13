<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Http\Requests\Admin\FaqRequest;
use Juwri\Modules\Content\Models\Faq;

/**
 * Admin › FAQ: questions for the marketing site's FAQ page, one list per
 * language. An entry without a language shows in every language's list.
 */
class FaqController extends Controller
{
    use AnswersAdminPanel;

    public function __construct()
    {
        $this->authorizeResource(Faq::class, 'faq');
    }

    public function index(Request $request): View|RedirectResponse
    {
        $code = $request->query('lang');
        if (! is_string($code) || $code === '') {
            $default = config('settings.lang', config('app.default_language'));

            return redirect(url()->current().'?lang='.urlencode((string) $default));
        }

        $language = Language::query()->where('code', $code)->firstOrFail();

        $faqs = Faq::with('language')
            ->where(fn ($q) => $q->where('translation_lang', $language->code)->orWhereNull('translation_lang'))
            ->get();

        return view('admin.faqs.index', [
            'faqs' => $faqs,
            'current_language' => $language->code,
        ]);
    }

    public function create(): View
    {
        return view('admin.faqs.create');
    }

    public function store(FaqRequest $request): JsonResponse
    {
        Faq::create($request->faqAttributes());

        return $this->panelDone(___('Created Successfully'));
    }

    public function show(Faq $faq): never
    {
        abort(404);
    }

    public function edit(Faq $faq): View
    {
        return view('admin.faqs.edit', ['faq' => $faq]);
    }

    public function update(FaqRequest $request, Faq $faq): JsonResponse
    {
        $faq->update($request->faqAttributes());

        return $this->panelDone(___('Updated Successfully'));
    }

    /** Delete button on the list (a plain form post, answered with a redirect). */
    public function destroy(Faq $faq): RedirectResponse
    {
        $faq->delete();
        app_alert_success(___('Deleted Successfully'));

        return back();
    }
}
