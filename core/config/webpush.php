<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

return [
    /*
    | VAPID keys for Web Push (#100 staff PWA). Generate once with
    | Minishlink\WebPush\VAPID::createVapidKeys() and store in .env.
    | Push is a no-op (logged) when keys are absent.
    */
    'subject'     => env('VAPID_SUBJECT', 'mailto:info@juwri.com'),
    'public_key'  => env('VAPID_PUBLIC_KEY', ''),
    'private_key' => env('VAPID_PRIVATE_KEY', ''),
];
