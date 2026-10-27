<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * I0 — the shared connection-UX partials render for any track that includes them.
 */
class IntegrationPartialsTest extends TestCase
{
    private function theme(): string
    {
        return 'templates.'.env('THEME_NAME', 'juwri').'.';
    }

    public function test_creds_form_renders_masked_secret(): void
    {
        $html = View::make('owner.integrations.partials.creds-form', [
            'action' => '/x/connect',
            'rid' => 5,
            'secrets' => ['api_key' => 'stored'],
            'fields' => [
                ['name' => 'api_key', 'label' => 'API key', 'type' => 'password', 'required' => true],
                ['name' => 'store_id', 'label' => 'Store id', 'col' => 6],
            ],
        ])->render();

        $this->assertStringContainsString('name="api_key"', $html);
        $this->assertStringContainsString('name="store_id"', $html);
        $this->assertStringContainsString(___('leave blank to keep'), $html); // masked, not the raw secret
        $this->assertStringNotContainsString('value="stored"', $html);
        $this->assertStringContainsString('store_id=5', $html);
    }

    public function test_connection_status_and_log_render(): void
    {
        $status = View::make('owner.integrations.partials.connection-status', [
            'connected' => true,
            'lastSynced' => '2 min ago',
            'testAction' => '/x/test',
            'rid' => 5,
            'label' => 'Foodics',
        ])->render();
        // Owner UI kit: the connected state is a success-toned kit pill.
        $this->assertStringContainsString('class="ui-pill" data-tone="success"', $status);
        $this->assertStringContainsString('action="/x/test?store_id=5"', $status);
        $this->assertStringContainsString('Foodics', $status);

        $log = View::make('owner.integrations.partials.sync-log-table', [
            'log' => collect([]),
        ])->render();
        $this->assertStringContainsString('integration-sync-log', $log);
        $log2 = View::make('owner.integrations.partials.sync-log-table', [
            'log' => collect([(object) ['created_at' => now(), 'status' => 'ok', 'message' => 'Pushed 3 items']]),
        ])->render();
        $this->assertStringContainsString('Pushed 3 items', $log2);
        $this->assertStringContainsString('class="ui-tag" data-tone="success"', $log2);
    }
}
