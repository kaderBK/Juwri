<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
| CORS overrides. Paths, methods, headers and max-age use the framework
| defaults. Allowed origins come from CORS_ALLOWED_ORIGINS (comma
| separated) so an operator can lock the token API to known clients; the
| '*' default is only acceptable because supports_credentials stays false
| (bearer tokens, no cookies cross-origin).
*/

return [

    'paths' => ['api/*'],

    'supports_credentials' => false,

    'allowed_origins' => array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '*'))),

];
