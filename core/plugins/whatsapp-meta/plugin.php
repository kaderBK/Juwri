<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/* Plugin Details */

return [
    'id' => 'whatsapp-meta',
    'name' => 'WhatsApp via Meta Cloud API',
    'description' => 'Official WhatsApp Business Cloud API: templates, interactive buttons/lists, catalog and Flow ordering, delivery/read receipts, and inbound webhooks. Supports platform keys or per-restaurant BYOK (Embedded Signup).',
    'image' => '',
    'version' => '1.0',
    'min_app_version' => '1.0',
    'url' => '',
    'author' => 'juwri',
    'author_url' => '',
    'settings_route' => 'admin.whatsapp.settings',

    /* Registers the Meta Cloud API driver as a selectable provider. */
    'whatsapp_provider' => 'meta',
    'whatsapp_driver' => \Juwri\Modules\Messaging\Services\WhatsApp\Drivers\MetaProvider::class,
];
