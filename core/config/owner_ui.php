<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * Owner dashboard redesign switches (rev/mobile-app plan, step 2).
 * Both default on; flip to false to fall back instantly without a deploy of
 * code (then run `php artisan config:clear` if config is cached).
 */
return [
    // assets/owner-ui/skin.css: the existing owner pages re-pointed at the
    // owner UI tokens (<html class="ui-skin">).
    'skin' => (bool) env('OWNER_UI_SKIN', true),

    // Phone bottom tab bar (partials/mobile-tabbar). The in-app chrome rules
    // (server item S2) always apply inside the native app.
    'mobile_tabbar' => (bool) env('OWNER_UI_MOBILE_TABBAR', true),
];
