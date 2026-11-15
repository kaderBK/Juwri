<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * WI-09d characterisation of the plugin registry helpers (PluginHelper.php):
 * installed-plugin discovery, lookup, the enabled check and plugin asset URLs.
 * Manifest parsing itself is covered by PluginManifestTest.
 */
class PluginHelperContractTest extends TestCase
{
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private function plugin(string $id, ?array $manifest): string
    {
        $dir = base_path('plugins/'.$id);
        File::ensureDirectoryExists($dir);
        if ($manifest !== null) {
            File::put($dir.'/plugin.json', json_encode($manifest));
        }
        $this->dirs[] = $dir;

        return $dir;
    }

    public function test_installed_plugins_are_keyed_by_folder_and_skip_folders_without_a_manifest(): void
    {
        $this->plugin('wi09dalpha', ['id' => 'wi09dalpha', 'name' => 'Alpha', 'version' => '1.2', 'meta' => ['k' => 'v']]);
        $this->plugin('wi09dempty', null);
        config(['settings.active_plugins' => ['wi09dalpha']]);

        $installed = get_installed_plugins();

        $this->assertIsArray($installed);
        $this->assertArrayHasKey('wi09dalpha', $installed);
        $this->assertArrayNotHasKey('wi09dempty', $installed);
        $alpha = $installed['wi09dalpha'];
        $this->assertIsObject($alpha);
        $this->assertSame('Alpha', $alpha->name);
        $this->assertSame('1.2', $alpha->version);
        $this->assertSame('v', $alpha->meta->k);   // nested manifest data becomes objects too
        $this->assertTrue($alpha->enabled);
        // The plugins shipped in the repo. A glob of plugins/* is not safe here:
        // parallel workers create and delete scratch folders there (wi09d*,
        // manifesttest_<pid>, wi08test<pid>) between the call above and the
        // check. Add new shipped plugins to this list.
        foreach (['mediamanager', 'whatsapp-link', 'whatsapp-meta', 'whatsapp-twilio'] as $shipped) {
            $this->assertArrayHasKey($shipped, $installed, "shipped plugin {$shipped} must be discovered");
        }
    }

    public function test_get_plugin_returns_false_without_a_manifest(): void
    {
        $this->assertFalse(get_plugin('wi09d-does-not-exist'));
        $this->plugin('wi09dempty', null);
        $this->assertFalse(get_plugin('wi09dempty'));
    }

    public function test_enabled_needs_a_manifest_and_the_active_list(): void
    {
        $this->plugin('wi09dbeta', ['id' => 'wi09dbeta', 'name' => 'Beta']);

        config(['settings.active_plugins' => []]);
        $this->assertFalse(is_plugin_enabled('wi09dbeta'));
        $this->assertFalse(get_plugin('wi09dbeta')->enabled);

        config(['settings.active_plugins' => ['wi09dbeta', 'wi09dghost']]);
        $this->assertTrue(is_plugin_enabled('wi09dbeta'));
        $this->assertTrue(get_plugin('wi09dbeta')->enabled);
        $this->assertFalse(is_plugin_enabled('wi09dghost'), 'listed as active but not installed');

        $legacy = $this->plugin('wi09dlegacy', null);
        File::put($legacy.'/plugin.php', '<?php return ["id" => "wi09dlegacy", "name" => "L"];');
        config(['settings.active_plugins' => ['wi09dlegacy']]);
        $this->assertTrue(is_plugin_enabled('wi09dlegacy'));
    }

    public function test_plugin_assets_url_or_404(): void
    {
        $this->plugin('wi09dgamma', ['id' => 'wi09dgamma', 'name' => 'Gamma']);

        config(['settings.active_plugins' => ['wi09dgamma']]);
        $this->assertSame(asset('core/plugins/wi09dgamma/assets/css/app.css'), plugin_assets('wi09dgamma', 'css/app.css'));

        config(['settings.active_plugins' => []]);
        try {
            plugin_assets('wi09dgamma', 'css/app.css');
            $this->fail('a disabled plugin must 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
