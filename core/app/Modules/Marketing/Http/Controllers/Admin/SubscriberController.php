<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Marketing\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Marketing\Http\Requests\Admin\SubscriberRequest;
use Juwri\Modules\Marketing\Models\Subscriber;

/** Admin › Newsletter: the addresses collected by the site's sign-up form. */
class SubscriberController extends Controller
{
    use AnswersAdminPanel;

    public function __construct()
    {
        $this->authorizeResource(Subscriber::class, 'subscriber');
    }

    public function index(): View
    {
        // Newest sign-ups first: the list grows without limit and the fresh rows
        // are what an admin looks at. The kit's table only filters (it never
        // sorts), so the order the server renders IS the order on screen.
        return view('admin.subscriber.index', ['subscribers' => Subscriber::latest('id')->get()]);
    }

    public function create(): View
    {
        return view('admin.subscriber.create');
    }

    public function store(SubscriberRequest $request): JsonResponse
    {
        Subscriber::create([
            'email' => $request->validated('email'),
            'joined' => now(),
        ]);

        return $this->panelDone(___('Created Successfully'));
    }

    public function show(Subscriber $subscriber): never
    {
        abort(404);
    }

    public function edit(Subscriber $subscriber): View
    {
        return view('admin.subscriber.edit', ['subscriber' => $subscriber]);
    }

    public function update(SubscriberRequest $request, Subscriber $subscriber): JsonResponse
    {
        $subscriber->update(['email' => $request->validated('email')]);

        return $this->panelDone(___('Updated Successfully'));
    }

    /** Delete button on the list (a plain form post, answered with a redirect). */
    public function destroy(Subscriber $subscriber): RedirectResponse
    {
        $subscriber->delete();
        app_alert_success(___('Deleted Successfully'));

        return back();
    }
}
