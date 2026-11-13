<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Illuminate\Support\Facades\Route;
use Tests\CommunityTestCase;

/**
 * The public pitch calculators and scanners are an edition site. A Community
 * install does not ship their scripts or the route that serves them.
 */
class PitchToolScriptsNotInCommunityTest extends CommunityTestCase
{
    public function test_pitch_tool_scripts_are_not_in_the_community_asset_tree(): void
    {
        $root = dirname(base_path()).'/assets/owner-ui/tools';

        foreach (['commission-calc.js', 'qr-menu-generator.js', 'menu-scanner.js', 'reseller-calc.js'] as $file) {
            $this->assertFileDoesNotExist($root.'/'.$file, $file);
        }
        $this->assertFalse(Route::has('platform.assets'));
    }
}
