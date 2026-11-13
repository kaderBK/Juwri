<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Illuminate\Support\Facades\Route;
use Tests\CommunityTestCase;

/**
 * The reseller panel skin is an edition asset. A Community install must not
 * ship it, and must not register the route that serves it.
 */
class ResellerPanelAssetsNotInCommunityTest extends CommunityTestCase
{
    public function test_reseller_panel_skins_are_not_in_the_community_asset_tree(): void
    {
        $root = dirname(base_path()).'/assets/owner-ui';

        $this->assertFileDoesNotExist($root.'/reseller.css');
        $this->assertFileDoesNotExist($root.'/reseller-panel.js');
        $this->assertFileDoesNotExist($root.'/reseller-public.css');
        $this->assertFalse(Route::has('reseller.assets'));
    }
}
