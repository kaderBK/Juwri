{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- aisle §6: on a phone the filters open as a full-height sheet with the same groups and a sticky "Show N products" button. --}}
<div id="filters" class="c-overlay c-overlay--filters" data-juwri-sheet role="dialog" aria-modal="true" aria-labelledby="filters-title-s" hidden>
    <div class="c-overlay__scrim" data-juwri-overlay aria-hidden="true"></div>
    <div class="c-fsheet">@include('store_templates.aisle.components.filters', ['idp' => 's', 'sheet' => true])</div>
</div>
