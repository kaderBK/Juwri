<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [
    'name' => 'juwri',
    'url' => 'https://juwri.com',
    'version' => '6.5',
    'buy_now_url' => 'https://juwri.com',
    // P6-114: optional public status page (any provider). Empty = no "System status" link.
    'status_page_url' => env('STATUS_PAGE_URL'),
];
