<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use Tests\TestCase;

class StoreTableUiContractTest extends TestCase
{
    public function test_table_number_input_matches_the_server_range(): void
    {
        $view = file_get_contents(resource_path('views/owner/tables/index.blade.php'));

        $this->assertStringContainsString(
            'name="table_number" class="ui-input" x-model="tableNo" required min="1" max="255"',
            $view
        );
    }
}
