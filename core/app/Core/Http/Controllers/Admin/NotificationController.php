<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Notification;

/**
 * Admin › Notifications: the superadmin's event feed. Opening an entry marks
 * it read and follows its link.
 */
class NotificationController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', Notification::class);

        return view('admin.notifications.index', [
            'notifications' => Notification::query()->latest('id')->get(),
            'totalUnread' => Notification::query()->where('status', 0)->count(),
        ]);
    }

    public function view(string $id): RedirectResponse
    {
        $notification = Notification::query()->findOrFail($id);
        $this->authorize('update', $notification);

        $notification->update(['status' => 1]);

        return redirect((string) $notification->link);
    }

    public function markAsRead(): RedirectResponse
    {
        $this->authorize('viewAny', Notification::class);

        Notification::query()->where('status', 0)->update(['status' => 1]);
        app_alert_success(___('All notifications marked as read'));

        return back();
    }

    public function deleteAllRead(): RedirectResponse
    {
        $this->authorize('deleteAny', Notification::class);

        Notification::query()->where('status', 1)->delete();
        app_alert_success(___('Deleted Successfully'));

        return back();
    }
}
