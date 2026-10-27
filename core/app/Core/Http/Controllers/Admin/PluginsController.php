<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers\Admin;

use App\Http\Requests\Admin\PluginUploadRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\File;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Policies\Admin\PanelAbilities;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Support\PluginInstaller;
use Juwri\Core\Support\PluginZipValidator;
use RuntimeException;

/**
 * Admin › Plugins: upload, enable, disable and remove plugins in `plugins/`.
 * There is no plugin marketplace any more, so the screen lists what is
 * installed. The enabled set is the `active_plugins` setting.
 */
class PluginsController extends Controller
{
    use AnswersAdminPanel;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->authorize(PanelAbilities::PLUGINS);

            return $next($request);
        });
    }

    public function index(): View
    {
        $installed = get_installed_plugins();

        $listed = collect($installed)
            ->each(function (object $plugin) {
                $plugin->installed = true;
                $plugin->is_compatible = $this->compatible($plugin);
            })
            ->values();

        return view('admin.plugins.index', [
            'plugins' => collect(),
            'installed_plugins' => $installed,
            'installed_plugins_not_in_marketplace' => $listed,
        ]);
    }

    public function create(): View
    {
        return view('admin.plugins.create');
    }

    public function store(PluginUploadRequest $request, PluginInstaller $installer): JsonResponse
    {
        try {
            $installer->install($request->file('plugin_zip'));
        } catch (RuntimeException $e) {
            return $this->panelFailed($e->getMessage());
        }

        return $this->panelDone(___('Plugin installed successfully.'));
    }

    public function enable(string $id): RedirectResponse
    {
        $plugin = $this->find($id);
        if (! $plugin) {
            return $this->backWithError(___('Plugin not found.'));
        }
        if ($plugin->enabled) {
            return $this->backWithError(___('Plugin is already enabled.'));
        }
        try {
            PluginInstaller::assertCompatible($plugin);
        } catch (RuntimeException $e) {
            return $this->backWithError($e->getMessage());
        }

        $this->saveActive([...$this->active(), $plugin->id]);

        return $this->backWithSuccess(___('Plugin is enabled.'));
    }

    public function disable(string $id): RedirectResponse
    {
        $plugin = $this->find($id);
        if (! $plugin) {
            return $this->backWithError(___('Plugin not found.'));
        }
        if (! $plugin->enabled) {
            return $this->backWithError(___('Plugin is already disabled.'));
        }

        $this->saveActive(array_values(array_diff($this->active(), [$plugin->id])));

        return $this->backWithSuccess(___('Plugin is disabled.'));
    }

    public function destroy(string $plugin): RedirectResponse
    {
        $found = $this->find($plugin);
        if (! $found) {
            return $this->backWithError(___('Plugin not found.'));
        }
        if ($found->enabled) {
            return $this->backWithError(___('Plugin is enabled, disable it first.'));
        }

        File::deleteDirectory(base_path('plugins/'.$found->id));

        return $this->backWithSuccess(___('Deleted Successfully'));
    }

    /** Plugins have no detail or edit page. */
    public function show(string $plugin): RedirectResponse
    {
        return redirect()->route('admin.plugins.index');
    }

    public function edit(string $plugin): RedirectResponse
    {
        return redirect()->route('admin.plugins.index');
    }

    public function update(string $plugin): RedirectResponse
    {
        return redirect()->route('admin.plugins.index');
    }

    /** An installed plugin by folder name; ids are plain folder names, never paths. */
    private function find(string $id): ?object
    {
        if (! PluginZipValidator::isSafePluginId($id)) {
            return null;
        }
        $plugin = get_plugin($id);

        return is_object($plugin) && PluginZipValidator::isSafePluginId($plugin->id ?? null) ? $plugin : null;
    }

    private function compatible(object $plugin): bool
    {
        return version_compare((string) config('appinfo.version'), (string) ($plugin->min_app_version ?? '0'), '>=');
    }

    /** @return list<string> */
    private function active(): array
    {
        return array_values(array_map('strval', (array) config('settings.active_plugins', [])));
    }

    /** @param list<string> $ids */
    private function saveActive(array $ids): void
    {
        PlatformSettings::set('active_plugins', array_values(array_unique($ids)));
    }

    /** Back to the plugin list with a toast of the given kind. */
    private function backWith(string $kind, string $message): RedirectResponse
    {
        app_alert($message, $kind);

        return redirect()->back();
    }

    private function backWithError(string $message): RedirectResponse
    {
        return $this->backWith('error', $message);
    }

    private function backWithSuccess(string $message): RedirectResponse
    {
        return $this->backWith('success', $message);
    }
}
