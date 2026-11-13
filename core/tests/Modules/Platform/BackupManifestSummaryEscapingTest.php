<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Controllers\Admin\UserController;
use Tests\TestCase;

/**
 * Security (F3): the backup import preview is echoed raw in the superadmin
 * panel; values from the uploaded manifest must be escaped.
 */
class BackupManifestSummaryEscapingTest extends TestCase
{
    public function test_manifest_name_and_plan_label_are_escaped(): void
    {
        $payload = '<img src=x onerror=alert(1)>';
        $method = new \ReflectionMethod(UserController::class, 'summarizeBackupManifest');
        $method->setAccessible(true);

        $html = $method->invoke(app(UserController::class), [
            'user' => ['name' => $payload, 'subscription' => ['plan_label' => $payload]],
        ]);

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }
}
