<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Content\Models\LockedTemplate;
use Juwri\Modules\Content\Models\LockedTemplatePermission;
use Juwri\Modules\Content\Models\LockedTemplateVersion;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Services\Builder\MergeTags;
use Juwri\Modules\Content\Services\LockedTemplates\Manifest;
use Juwri\Modules\Content\Services\LockedTemplates\Permissions;
use Juwri\Modules\Content\Services\LockedTemplates\Renderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Juwri\Core\Contracts\Content\ResellerContent;

/**
 * Superadmin management of locked templates: catalogue, preview, publishing,
 * new versions (edited manifest JSON), and who may edit what (global default caps
 * + per-user overrides). Lives in the admin group (AdminMiddleware), so only
 * platform admins reach it — the template CODE is only ever authored here.
 */
class LockedTemplateController extends Controller
{
    public function index(): View
    {
        $templates = LockedTemplate::query()->orderBy('name')->get();
        $usage = collect(app(ResellerContent::class)->lockedTemplateUsage());
        $versionCounts = LockedTemplateVersion::query()
            ->selectRaw('locked_template_id, count(*) as n')
            ->groupBy('locked_template_id')
            ->pluck('n', 'locked_template_id');

        $overrides = LockedTemplatePermission::query()->latest('updated_at')->limit(200)->get();
        $overrideUsers = User::query()->whereIn('id', $overrides->pluck('user_id'))->get(['id', 'name', 'email'])->keyBy('id');

        return view('admin.locked-templates.index', [
            'templates' => $templates,
            'usage' => $usage,
            'versionCounts' => $versionCounts,
            'defaults' => Permissions::defaults(),
            'caps' => Permissions::CAPS,
            'overrides' => $overrides,
            'overrideUsers' => $overrideUsers,
        ]);
    }

    /** Standalone render of a version with the template defaults (no user content). */
    public function preview(Request $request, int $id): Response
    {
        $template = LockedTemplate::query()->findOrFail($id);
        $version = $request->filled('version')
            ? $template->versionNumber((int) $request->input('version'))
            : ($template->currentVersion() ?? $template->latestVersion());
        abort_unless($version !== null, 404);

        $lt = Renderer::render($version, null, [
            'lang' => get_lang(),
            'tokens' => MergeTags::forPreview(),
        ]);

        return response()->view('locked-templates.page', [
            'lt' => $lt,
            'title' => $template->name.' — v'.$version->version.' '.___('preview'),
            'headExtra' => '<meta name="robots" content="noindex,nofollow">',
        ]);
    }

    public function setStatus(Request $request, int $id): RedirectResponse
    {
        $template = LockedTemplate::query()->findOrFail($id);
        $request->validate(['status' => 'required|in:draft,published']);

        if ($request->input('status') === 'published') {
            $latest = $template->latestVersion();
            abort_unless($latest !== null, 422, ___('Import or create a version first.'));
            $template->current_version = $template->current_version ?? $latest->version;
        }
        $template->status = (string) $request->input('status');
        $template->save();

        return back()->with('success', $template->status === 'published' ? ___('Template published.') : ___('Template hidden from users.'));
    }

    public function setCurrent(Request $request, int $id): RedirectResponse
    {
        $template = LockedTemplate::query()->findOrFail($id);
        $version = $template->versionNumber((int) $request->input('version'));
        abort_unless($version !== null, 404);
        $template->current_version = $version->version;
        $template->save();

        return back()->with('success', ___('New pages will start on version :v.', ['v' => $version->version]));
    }

    /** Edit a version's manifest JSON as the starting point of a NEW version. */
    public function newVersion(Request $request, int $id): View
    {
        $template = LockedTemplate::query()->findOrFail($id);
        $from = $request->filled('from') ? $template->versionNumber((int) $request->input('from')) : $template->latestVersion();
        abort_unless($from !== null, 404);

        return view('admin.locked-templates.version', [
            'template' => $template,
            'from' => $from,
            'json' => json_encode($from->manifestData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function storeVersion(Request $request, int $id): RedirectResponse
    {
        $template = LockedTemplate::query()->findOrFail($id);
        $request->validate([
            'manifest' => 'required|string|max:4000000',
            'notes' => 'nullable|string|max:255',
            'from' => 'required|integer',
        ]);
        $from = $template->versionNumber((int) $request->input('from'));
        abort_unless($from !== null, 404);

        $m = json_decode((string) $request->input('manifest'), true);
        if (! is_array($m)) {
            return back()->withInput()->withErrors(['manifest' => ___('The manifest is not valid JSON.')]);
        }
        // The section library isn't part of the edited JSON; it carries over from the
        // source version and must still fit the edited manifest (no key collisions).
        $library = $from->libraryData();
        $errors = Manifest::validate($library !== [] ? $m + ['library' => $library] : $m);
        if ($errors) {
            return back()->withInput()->withErrors(['manifest' => implode("\n", $errors)]);
        }

        $next = (int) LockedTemplateVersion::query()->where('locked_template_id', $template->id)->max('version') + 1;
        $m['meta'] = array_merge((array) ($m['meta'] ?? []), ['key' => $template->key, 'version' => $next]);

        LockedTemplateVersion::query()->create([
            'locked_template_id' => $template->id,
            'version' => $next,
            'manifest' => (string) json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'library' => $from->library,
            // Assets are shared with the source version (the JSON edit changes markup/slots, not files).
            'assets_base' => $from->assets_base,
            'notes' => (string) $request->input('notes', '') ?: null,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('admin.locked-templates.index')
            ->with('success', ___('Version :v created. Existing pages stay on their version until their owner updates.', ['v' => $next]));
    }

    public function saveDefaults(Request $request): RedirectResponse
    {
        Permissions::saveDefaults((array) $request->input('caps', []));

        return back()->with('success', ___('Default editing permissions saved.'));
    }

    /** Per-user override (from the admin user page or this screen). */
    public function saveUser(Request $request): RedirectResponse
    {
        $request->validate([
            'user_id' => 'required|integer',
            'mode' => 'required|in:custom,default',
            'templates' => 'nullable|array',
            'templates.*' => 'integer',
        ]);
        $userId = (int) $request->input('user_id');
        abort_unless(User::query()->whereKey($userId)->exists(), 404);

        if ($request->input('mode') === 'default') {
            Permissions::resetUser($userId);

            return back()->with('success', ___('This user now follows the default editing permissions.'));
        }

        $allowed = $request->boolean('all_templates') ? null : array_map('intval', (array) $request->input('templates', []));
        Permissions::saveForUser($userId, (array) $request->input('caps', []), $allowed, auth()->id());

        return back()->with('success', ___('Editing permissions saved for this user.'));
    }

    /** Find a user by email for the "add override" form on the index screen. */
    public function findUser(Request $request): RedirectResponse
    {
        $request->validate(['email' => 'required|email']);
        $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $request->input('email')))])->first();
        if (! $user) {
            return back()->withErrors(['email' => ___('No user with that email.')]);
        }

        return redirect()->to(route('admin.users.edit', $user->id).'#locked-template-permissions');
    }
}
