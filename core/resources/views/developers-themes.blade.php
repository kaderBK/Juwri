{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P2-7: public theme developer documentation. The Drop + filter references
     are GENERATED from the actual classes (DeveloperDocsController) so this
     page cannot drift from what a template can really touch.

     EXTENDS the marketing frame (marketing.layout) so it carries the exact site
     nav + footer + fonts + theme toggle + language picker (one design language
     with the home). The doc content lives under a scoped `.dev-docs` wrapper —
     every selector is namespaced (dd-*). --}}
@extends('marketing.layout')

@section('title', $siteName.' — Theme developer docs')

@push('styles')
<style nonce="{{ csp_nonce() }}">
    /* Doc content adopts the home light/dark tokens + purple accent so it reads
       as one page with the nav/footer, not a foreign slab. */
    .dev-docs { --dd-text:#0a0910; --dd-muted:#6c6b70; --dd-acc:#8b3dff; --dd-panel:#f5f4f7; --dd-border:#e7e6ea; color:var(--dd-text); }
    :root[data-theme="dark"] .dev-docs { --dd-text:#e6edf3; --dd-muted:#9aa0a6; --dd-panel:#161b22; --dd-border:#2a2f37; }
    .dev-docs a { color:var(--dd-acc); text-decoration:none; } .dev-docs a:hover { text-decoration:underline; }
    .dev-docs .layout { display:grid; grid-template-columns:220px minmax(0, 1fr); gap:40px; max-width:1100px; margin:0 auto; padding:32px 24px 80px; }
    .dev-docs .dd-main { min-width:0; }
    .dev-docs .dd-main :is(p, li, h2, h3) { overflow-wrap:anywhere; }
    .dev-docs nav.toc { position:sticky; top:100px; align-self:start; font-size:14px; }
    .dev-docs nav.toc a { display:block; color:var(--dd-muted); padding:5px 0; padding-inline-start:12px; border-inline-start:2px solid transparent; }
    .dev-docs nav.toc a:hover { color:var(--dd-text); border-color:var(--dd-acc); text-decoration:none; }
    .dev-docs section { margin-bottom:48px; scroll-margin-top:100px; }
    .dev-docs h2 { font-size:24px; margin:0 0 12px; color:var(--dd-text); }
    .dev-docs h3 { font-size:17px; margin:24px 0 8px; color:var(--dd-text); }
    .dev-docs p, .dev-docs li { color:var(--dd-muted); }
    .dev-docs code { background:var(--dd-panel); border:1px solid var(--dd-border); border-radius:6px; padding:1px 6px; font-size:13.5px; color:var(--dd-text); }
    .dev-docs pre { background:var(--dd-panel); border:1px solid var(--dd-border); border-radius:10px; padding:16px; overflow-x:auto; font-size:13.5px; line-height:1.55; color:var(--dd-text); }
    .dev-docs pre code { background:none; border:0; padding:0; }
    .dev-docs table { border-collapse:collapse; width:100%; font-size:14px; margin:10px 0 4px; }
    .dev-docs th, .dev-docs td { text-align:start; padding:7px 10px; border-bottom:1px solid var(--dd-border); }
    .dev-docs th { color:var(--dd-muted); font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:.06em; }
    .dev-docs td code { font-size:12.5px; }
    .dev-docs .dd-pill { display:inline-block; background:var(--dd-panel); border:1px solid var(--dd-border); border-radius:999px; padding:2px 10px; font-size:12.5px; margin:2px 4px 2px 0; }
    .dev-docs .dd-btn { display:inline-block; background:var(--dd-acc); color:#fff; font-weight:700; padding:10px 22px; border-radius:800px; }
    .dev-docs .dd-btn:hover { text-decoration:none; opacity:.9; }
    @media (max-width:800px) { .dev-docs .layout { grid-template-columns:1fr; } .dev-docs nav.toc { position:static; } }
</style>
@endpush

@section('content')
<div class="dev-docs">
<div class="layout">
    <nav class="toc">
        <a href="#start">{{ ___('Getting started') }}</a>
        <a href="#package">{{ ___('Package layout') }}</a>
        <a href="#manifest">theme.json {{ ___('manifest') }}</a>
        <a href="#templates">{{ ___('Templates & sections') }}</a>
        <a href="#data">{{ ___('Data (Drop reference)') }}</a>
        <a href="#filters">{{ ___('Filters & tags') }}</a>
        <a href="#commerce">{{ ___('Commerce runtime') }}</a>
        <a href="#settings">{{ ___('Merchant settings') }}</a>
        <a href="#rules">{{ ___('Rules & limits') }}</a>
    </nav>

    <div class="dd-main">
        <section id="start">
            <h2>{{ ___('Getting started') }}</h2>
            <p>{{ ___('Themes are') }} <b>{{ ___('sandboxed Liquid') }}</b> — {{ ___('data-only templates rendered server-side against a fixed data contract. Themes ship') }} <b>{{ ___('zero JavaScript') }}</b>: {{ ___('cart, item options and checkout come from the platform commerce runtime, which your markup binds to declaratively.') }}</p>
            <p><a class="dd-btn" href="{{ route('developers.themes.starter') }}">{{ ___('Download the starter theme (.zip)') }}</a></p>
            <ol>
                <li>{{ ___('Download and unzip the starter.') }}</li>
                <li>{{ ___('Rename the') }} <code>slug</code> {{ ___('in') }} <code>theme.json</code>.</li>
                <li>{{ ___('Edit sections in') }} <code>sections/</code>; {{ ___('add snippets in') }} <code>snippets/</code>.</li>
                <li>{{ ___('Zip the folder contents and submit it for review.') }}</li>
            </ol>
        </section>

        <section id="package">
            <h2>{{ ___('Package layout') }}</h2>
<pre><code>mytheme.zip
├── theme.json                  # manifest (data, never code)
├── templates/index.json        # OS 2.0 sectioned template (or index.liquid single-file)
├── sections/*.liquid           # section files, each with its schema block
├── snippets/*.liquid           # reusable partials for the render tag
├── config/settings_schema.json # theme-level settings (Shopify-style groups)
├── locales/en.json …           # theme strings for the t filter
├── assets/                     # css / images / fonts — static only
└── preview.png                 # listing screenshot</code></pre>
            <p>{{ ___('Caps:') }} <b>10&nbsp;MB</b> {{ ___('zip,') }} <b>2&nbsp;MB</b> {{ ___('per file, folder depth ≤ 3. Allowed file types:') }} <code>.liquid .json .css .png .jpg .jpeg .webp .svg .woff2</code>.
               <b>{{ ___('No PHP. No JavaScript.') }}</b> {{ ___('SVGs must contain no script or foreignObject.') }}</p>
        </section>

        <section id="manifest">
            <h2>theme.json {{ ___('manifest') }}</h2>
<pre><code>{
  "name": "My Theme",
  "slug": "my-theme",          // letters/numbers/dashes — becomes the install path
  "version": "1.0.0",          // published versions are immutable; ship updates as new versions
  "author": "You",
  "description": "…",
  "min_platform_version": "2.0"
}</code></pre>
        </section>

        <section id="templates">
            <h2>{{ ___('Templates & sections (OS 2.0)') }}</h2>
            <p><code>templates/index.json</code> {{ ___('lists') }} <b>{{ ___('section instances') }}</b>; {{ ___('each section is a Liquid file in') }}
               <code>sections/</code> {{ ___('carrying its settings schema in a schema block. Limits:') }}
               <b>{{ ___('≤ 25 sections') }}</b> {{ ___('per template,') }} <b>{{ ___('≤ 50 blocks') }}</b> {{ ___('per section.') }}</p>
<pre><code>// templates/index.json
{
  "sections": {
    "hero":  { "type": "hero", "settings": { "heading": "Welcome" } },
    "menu":  { "type": "menu-grid",
               "blocks": { "b1": { "type": "badge", "settings": { "label": "New" } } },
               "block_order": ["b1"] }
  },
  "order": ["hero", "menu"]
}</code></pre>
            <p>{{ ___('Inside a section file you receive') }} <code>section.id</code>, <code>section.type</code>,
               <code>section.settings.*</code> {{ ___('and') }} <code>section.blocks</code> ({{ ___('each block:') }}
               <code>id / type / settings.*</code>). {{ ___('Setting types:') }} <code>text</code>, <code>textarea</code>,
               <code>color</code>, <code>checkbox</code>, <code>select</code> ({{ ___('options') }}), <code>range</code>
               ({{ ___('min/max') }}), <code>image_picker</code>.</p>
        </section>

        <section id="data">
            <h2>{{ ___('Data — the Drop reference') }}</h2>
            <p>{{ ___('Templates can reach') }} <b>{{ ___('only') }}</b> {{ ___('the properties below (generated from the platform\'s Drop classes — this table cannot drift). Globals:') }}
               <code>restaurant</code>, <code>options</code>, <code>settings</code>, <code>menu</code>,
               <code>allergies</code>, <code>banners</code>, <code>branches</code>, <code>active_branch</code>,
               <code>table</code>, <code>customer</code>, <code>localization</code>, <code>flags</code>,
               <code>stats</code>, {{ ___('and') }} <code>section</code> {{ ___('inside section files. Anything else renders blank (and errors at upload).') }}</p>
            @foreach($drops as $drop => $props)
                <h3>{{ $drop }}</h3>
                <p>
                    @foreach($props as $prop)
                        <span class="dd-pill"><code>{{ $prop['name'] }}</code> <small>{{ str_replace('?', '', $prop['type']) }}</small></span>
                    @endforeach
                </p>
            @endforeach
        </section>

        <section id="filters">
            <h2>{{ ___('Filters & tags') }}</h2>
            <p>{{ ___('Platform filters (plus the standard safe Liquid set — escape, date, where, map, sort, size…):') }}</p>
            <p>@foreach($filters as $filter)<span class="dd-pill"><code>{{ $filter }}</code></span>@endforeach</p>
            <ul>
                <li><code>t</code> — {{ ___('translate a key. Read-only; unknown keys return the key itself.') }}</li>
                <li><code>money</code> / <code>money_code</code> — {{ ___('format a price with the restaurant\'s currency.') }}</li>
                <li><code>media_url</code> — {{ ___('restaurant image URL:') }} <code>@{{ item.image | media_url: 'menu' }}</code> ({{ ___('types: menu, logo, cover, allergy, banner') }}).</li>
                <li><code>theme_asset</code> — {{ ___('URL of one of your bundled assets:') }} <code>@{{ 'css/style.css' | theme_asset }}</code>.</li>
            </ul>
            <p>{{ ___('Tags:') }} <code>schema</code> ({{ ___('section settings, stripped from output') }}), <code>render</code>
               ({{ ___('snippets by name — your') }} <code>snippets/</code> {{ ___('dir only') }}), <code>commerce</code> ({{ ___('emits the commerce runtime once per page') }}). {{ ___('Unknown filters/tags fail at upload.') }}</p>
        </section>

        <section id="commerce">
            <h2>{{ ___('Commerce runtime') }}</h2>
            <p>{{ ___('Drop') }} <code>{% commerce %}</code> {{ ___('once (usually at the end of your template), then bind with data attributes — the runtime owns cart state, the option sheet and checkout:') }}</p>
<pre><code>&lt;button data-mb-add="@{{ item.id }}"&gt;Add&lt;/button&gt;
&lt;span   data-mb-cart-count&gt;&lt;/span&gt;
&lt;span   data-mb-cart-total&gt;&lt;/span&gt;
&lt;button data-mb-open-cart&gt;Cart&lt;/button&gt;
&lt;button data-mb-call-waiter&gt;Call waiter&lt;/button&gt;
&lt;div    data-mb-item-sheet-mount hidden&gt;&lt;/div&gt;
&lt;div    data-mb-cart-mount hidden&gt;&lt;/div&gt;</code></pre>
            <p>{{ ___('Skin the runtime UI via its') }} <code>.mb-*</code> {{ ___('classes and CSS custom properties') }}
               (<code>--mb-sheet-bg</code>, <code>--mb-sheet-ink</code>, <code>--mb-accent</code>).</p>
        </section>

        <section id="settings">
            <h2>{{ ___('Merchant settings') }}</h2>
            <p>{{ ___('Merchants customize your theme in the dashboard against your schemas. Precedence: schema defaults ← template values ← merchant values. Settings are stored independently of the theme version, so') }} <b>{{ ___('shipping an update never wipes a merchant\'s customization') }}</b>.</p>
        </section>

        <section id="rules">
            <h2>{{ ___('Rules & limits') }}</h2>
            <ul>
                <li>{{ ___('No') }} <code>&lt;script&gt;</code>, {{ ___('no inline event handlers, no') }} <code>javascript:</code> {{ ___('URLs, no iframes — rejected at upload.') }}</li>
                <li>{{ ___('Escape user-visible data:') }} <code>@{{ item.name | escape }}</code>.</li>
                <li>{{ ___('Renders are resource-bounded (output length + engine work caps). A section that fails at runtime is skipped, never a blank page; at upload it is a hard error.') }}</li>
                <li>{{ ___('Published versions are immutable — updates are new versions through review.') }}</li>
                <li>{{ ___('Use logical CSS properties') }} (<code>inline-size</code>, <code>margin-inline</code>…) — {{ ___('menus render RTL too.') }}</li>
            </ul>
        </section>
    </div>
</div>
</div>
@endsection
