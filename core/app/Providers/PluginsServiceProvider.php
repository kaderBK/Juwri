<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Providers;

use Juwri\Modules\Messaging\Services\WhatsApp\WhatsAppProviderFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires every enabled plugin (core/plugins/<id>, enabled = listed in
 * settings.active_plugins) into the app: its views under the `<id>::`
 * namespace, its routes/web.php inside the `web` group with the plugin's
 * controller namespace, and a WhatsApp driver its manifest declares.
 */
class PluginsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (get_installed_plugins() as $plugin) {
            if ($plugin->enabled) {
                $this->wirePlugin($plugin);
            }
        }
    }

    private function wirePlugin(object $plugin): void
    {
        $id = (string) $plugin->id;
        $root = plugin_dir($id);

        // realpath() is false without a views folder; registering that path
        // would break `view:cache`.
        if (($views = realpath($root.'/resources/views')) !== false) {
            $this->loadViewsFrom($views, $id);
        }

        if (($routes = realpath($root.'/routes/web.php')) !== false) {
            Route::middleware('web')
                ->namespace('Plugins\\'.$id.'\\app\\Http\\Controllers')
                ->group(fn () => $this->loadRoutesFrom($routes));
        }

        if (! empty($plugin->whatsapp_provider) && ! empty($plugin->whatsapp_driver)) {
            WhatsAppProviderFactory::register($plugin->whatsapp_provider, $plugin->whatsapp_driver);
        }
    }
}
