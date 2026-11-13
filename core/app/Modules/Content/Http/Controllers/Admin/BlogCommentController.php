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
use Juwri\Core\Http\Requests\Admin\BulkDeleteRequest;
use Juwri\Core\Support\Admin\Cells;
use Juwri\Core\Support\Admin\DataTable;
use Juwri\Modules\Content\Models\BlogComment;

/**
 * Admin › Blog › Comments: the moderation queue, optionally for one post
 * (`?blog_id=`). Comments come from guests too, so every value shown here is
 * escaped (the list renders in the superadmin session). 404 while the blog
 * is switched off.
 */
class BlogCommentController extends Controller
{
    use AnswersAdminPanel;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless((bool) config('settings.blog_enable'), 404);

            return $next($request);
        });
    }

    public function index(Request $request): View|JsonResponse
    {
        $this->authorize('viewAny', BlogComment::class);

        $blogId = (string) $request->input('blog_id', '');

        if (! $request->ajax()) {
            return view('admin.blog.comments.index', ['blog_id' => $blogId]);
        }

        $comments = BlogComment::query()->with(['user', 'blog'])
            ->when($blogId !== '', fn ($q) => $q->where('blog_id', (int) $blogId));

        $sortable = [0 => 'user_id', 1 => 'blog_id', 2 => 'comment', 3 => 'active', 4 => 'created_at'];

        return DataTable::make($request, $sortable, ['comment'])
            ->respond($comments, fn (BlogComment $comment) => [
                Cells::html($this->author($comment)),
                Cells::html($this->post($comment)),
                Cells::text($comment->comment),
                Cells::html((string) $comment->active === '1'
                    ? Cells::badge(___('Approved'), 'success')
                    : Cells::badge(___('Pending'), 'warning')),
                Cells::text(date_formating($comment->created_at)),
                Cells::actions((string) $comment->active === '1' ? '' : $this->approveButton($comment)),
                Cells::checkbox($comment->id),
            ]);
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        $comment = BlogComment::query()->findOrFail($id);
        $this->authorize('update', $comment);

        $comment->update(['active' => '1']);
        app_alert_success(___('Approved Successfully'));

        return back();
    }

    public function delete(BulkDeleteRequest $request): JsonResponse
    {
        $this->authorize('deleteAny', BlogComment::class);

        BlogComment::query()->whereKey($request->ids())->delete();

        return $this->panelDone(___('Deleted Successfully'));
    }

    /** Member: name linking to the account. Guest: the name and e-mail they typed. */
    private function author(BlogComment $comment): string
    {
        if ($comment->user) {
            return '<a href="'.e(route('admin.users.edit', $comment->user->id)).'">'.e($comment->user->name).'</a>';
        }

        return e((string) $comment->name).' <small>('.e(___('Guest')).')</small><br><small>'.e((string) $comment->email).'</small>';
    }

    private function post(BlogComment $comment): string
    {
        if (! $comment->blog) {
            return '&mdash;';
        }

        return '<a href="'.e(route('admin.blogs.edit', $comment->blog->id)).'">'
            .e(text_shorting($comment->blog->title, 30)).'</a>';
    }

    private function approveButton(BlogComment $comment): string
    {
        return '<form class="ui-inline" method="POST" action="'.e(route('admin.blog.comments.approve', $comment->id)).'">'
            .csrf_field()
            .'<button type="submit" class="ui-icon-btn ui-icon-btn--ghost" title="'.e(___('Approve')).'" aria-label="'.e(___('Approve')).'">'
            .Cells::icon('check').'</button>'
            .'</form>';
    }
}
