{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    ============================================================================
    google-suite Lane B (B3) — Menu JSON-LD toggle field (SELF-CONTAINED)
    ============================================================================

    WHAT: a per-store opt-in that adds the schema.org MenuItem tree (dish names +
    Offer{price,priceCurrency} + diet/image) to the storefront JSON-LD. Stored in
    post_option `menu_seo_jsonld` (STRING '1'/'0', DEFAULT OFF). It gates ONLY the
    hasMenu MenuItem tree — the Restaurant-node fields always emit.

    FOR LANE D (integrations page, `owner/...`): include this ONCE,
    OUTSIDE your own <form> (it carries its own <form> + @csrf and posts to the
    Lane-B route `jsonld.menu.toggle.save`), passing the store id:

        @includeIf('store_templates._partials.jsonld-toggle-field', ['storeId' => $store->id])

    @includeIf (not @include) is required: this partial lives only on branch B
    until the F0 merge, so a plain @include would throw "view not found" and fail
    Lane D's tests. Post-merge, F0 confirms it resolves.

    Self-contained: no external CSS/JS and no inline script or style tags (dashboard
    CSP safe). Renders nothing without a resolvable store id, so @includeIf is a
    no-op when the id isn't in scope.
    ============================================================================
--}}
@php
    $__jtId = $storeId ?? ($store->id ?? null);
    $__jtOn = $__jtId
        ? in_array(store_options($__jtId, 'menu_seo_jsonld'), [true, 1, '1', 'true', 'on'], true)
        : false;
@endphp
@if($__jtId)
<div class="jsonld-menu-toggle-field" data-jsonld-menu-toggle>
    <form method="POST" action="{{ route('jsonld.menu.toggle.save', $__jtId) }}">
        @csrf
        <label class="jsonld-menu-toggle-field__label">
            <input type="checkbox" name="menu_seo_jsonld" value="1" @checked($__jtOn)>
            <span>{{ ___('Rich menu results (SEO)') }}</span>
        </label>
        <p class="jsonld-menu-toggle-field__help">
            {{ ___('Publish your dish names and prices as structured data so search engines can show a rich menu for this store. Off by default.') }}
        </p>
        <button type="submit" class="jsonld-menu-toggle-field__save">{{ ___('Save') }}</button>
    </form>
</div>
@endif
