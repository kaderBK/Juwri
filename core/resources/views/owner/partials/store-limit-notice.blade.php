{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Edition limits (StoreLimitGuard): the store/branch limit message after a refused create — stays on the page (the
     toast fades). Points to the Chain licence / juwri Cloud; never a price. --}}
@if (is_array(session('store_limit')) && filled(session('store_limit')['message'] ?? null))
    <x-ui.banner tone="warning" data-store-limit="{{ session('store_limit')['reason'] ?? '' }}">{{ session('store_limit')['message'] }}</x-ui.banner>
@endif
