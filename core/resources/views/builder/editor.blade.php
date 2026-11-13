{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    // Shared html-mode ("advanced") editor, built on GrapesJS (BSD-3-Clause, vendored
    // at assets/vendor/grapesjs — juwri WI-05d). Surface-agnostic: the controller passes
    // a $builder cfg (route urls + title + back), $templates (gallery), $designData (the
    // stored editor state), $baseTemplate and $draftHtml (the saved, sanitised draft).
    // Used by reseller pages, CMS pages, biolink, reseller emails and custom blocks.
    //
    // Save contract (unchanged from the Builder.js editor, see BuilderSurfacesContractTest):
    // PUT html (<style>css</style> + markup), design (JSON editor state), base (template id).
    $pageTitle = $builder['title'] ?? ___('Untitled');
    $builderBackLabel = (string) ($builder['backLabel'] ?? ___('Back to pages'));
    $locale = strtolower(substr(str_replace('_', '-', app()->getLocale()), 0, 2));
    $htmlLang = str_replace('_', '-', app()->getLocale());
    $gjsLocales = ['ar', 'de', 'es', 'fr', 'pt', 'ru', 'tr', 'zh'];
    $gjsDir = 'assets/vendor/grapesjs';
@endphp
<!DOCTYPE html>
<html lang="{{ $htmlLang }}" dir="ltr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ ___('Builder') }} — {{ $pageTitle }}</title>
    <link rel="stylesheet" href="{{ \Juwri\Core\Support\ViewHelpers::editorAsset($gjsDir.'/grapes.min.css') }}">
    <style nonce="{{ csp_nonce() }}">
        :root { --be-bar: 56px; --be-ink: #17171c; --be-muted: #5b5b66; --be-line: #e6e3dc; --be-bg: #f4f2ed; --be-accent: #4f46e5; }
        html, body { height: 100%; margin: 0; }
        body { display: flex; flex-direction: column; background: var(--be-bg); color: var(--be-ink); font: 14px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .be-bar { display: flex; align-items: center; gap: 8px; height: var(--be-bar); padding: 0 12px; border-bottom: 1px solid var(--be-line); background: #fff; flex: none; }
        .be-bar .be-grow { flex: 1; min-width: 0; }
        .be-title { overflow: hidden; padding: 6px 12px; border-radius: 10px; background: var(--be-bg); font-weight: 600; white-space: nowrap; text-overflow: ellipsis; max-width: 260px; }
        .be-btn { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 14px; border: 1px solid var(--be-line); border-radius: 10px; background: #fff; color: var(--be-ink); font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .be-btn:hover { background: var(--be-bg); }
        .be-btn:focus-visible { outline: 3px solid color-mix(in srgb, var(--be-accent) 30%, transparent); outline-offset: 1px; }
        .be-btn[aria-pressed="true"] { border-color: var(--be-ink); }
        .be-btn[disabled] { opacity: .6; cursor: progress; }
        .be-primary { border-color: var(--be-accent); background: var(--be-accent); color: #fff; }
        .be-primary:hover { background: color-mix(in srgb, var(--be-accent) 88%, black); }
        .be-success { border-color: #15803d; background: #15803d; color: #fff; }
        .be-success:hover { background: #116932; }
        .be-group { display: inline-flex; gap: 4px; }
        .be-btn svg { width: 18px; height: 18px; }
        .be-label { display: inline; }
        @media (max-width: 900px) { .be-label { display: none; } .be-title { max-width: 120px; } }
        #gjs { flex: 1; min-height: 0; }
        .be-toasts { position: fixed; inset-inline-end: 16px; bottom: 16px; z-index: 100; display: flex; flex-direction: column; gap: 8px; }
        .be-toast { padding: 12px 16px; border-radius: 12px; background: #17171c; color: #fff; box-shadow: 0 8px 24px rgba(0, 0, 0, .18); }
        .be-toast.is-error { background: #b91c1c; }
        .be-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px; }
        .be-card { display: block; width: 100%; padding: 0; overflow: hidden; border: 1px solid var(--be-line); border-radius: 12px; background: #fff; color: var(--be-ink); font: inherit; text-align: start; cursor: pointer; }
        .be-card:hover, .be-card:focus-visible { border-color: var(--be-accent); }
        .be-card.is-current { outline: 2px solid var(--be-accent); }
        .be-card img, .be-card .be-noimg { display: block; width: 100%; aspect-ratio: 596 / 769; object-fit: cover; background: var(--be-bg); }
        .be-card span { display: block; padding: 8px 10px 0; font-weight: 600; }
        .be-card small { display: block; padding: 2px 10px 10px; color: var(--be-muted); }
        .be-note { margin: 0 0 14px; color: var(--be-muted); }
    </style>
</head>

<body>
    <header class="be-bar">
        <a href="{{ $builder['backUrl'] }}" class="be-btn" title="{{ $builderBackLabel }}">
            <x-ui.icon name="arrow-left" /><span class="be-label">{{ $builderBackLabel }}</span>
        </a>
        <span class="be-title">{{ $pageTitle }}</span>
        <span class="be-grow"></span>
        <span class="be-group" role="group" aria-label="{{ ___('Device preview') }}">
            <button type="button" class="be-btn" data-device="Desktop" aria-pressed="true" title="{{ ___('Desktop') }}"><x-ui.icon name="device-desktop" /></button>
            <button type="button" class="be-btn" data-device="Tablet" aria-pressed="false" title="{{ ___('Tablet') }}"><x-ui.icon name="device-tablet" /></button>
            <button type="button" class="be-btn" data-device="Mobile portrait" aria-pressed="false" title="{{ ___('Mobile') }}"><x-ui.icon name="device-mobile" /></button>
        </span>
        <span class="be-group">
            <button type="button" class="be-btn" id="beUndo" title="{{ ___('Undo') }}"><x-ui.icon name="arrow-back-up" /></button>
            <button type="button" class="be-btn" id="beRedo" title="{{ ___('Redo') }}"><x-ui.icon name="arrow-forward-up" /></button>
        </span>
        <span class="be-grow"></span>
        <button type="button" class="be-btn" id="beTemplates"><x-ui.icon name="layout" /><span class="be-label">{{ ___('Templates') }}</span></button>
        <button type="button" class="be-btn" id="bePreview" title="{{ ___('Preview in a new tab') }}"><x-ui.icon name="external-link" /><span class="be-label">{{ ___('Preview') }}</span></button>
        <button type="button" class="be-btn be-primary" id="beSave"><x-ui.icon name="device-floppy" /><span>{{ ___('Save') }}</span></button>
        <button type="button" class="be-btn be-success" id="bePublish"><x-ui.icon name="upload" /><span>{{ ___('Publish') }}</span></button>
    </header>

    <div id="gjs"></div>

    {{-- Template gallery (shown in the editor's modal). --}}
    <template id="beGallery">
        <p class="be-note">{{ ___('Pick a starting template. Applying one replaces the current page content.') }}</p>
        <div class="be-gallery">
            @foreach ($templates as $tpl)
                <button type="button" class="be-card {{ $tpl['id'] === $baseTemplate ? 'is-current' : '' }}" data-tpl-id="{{ $tpl['id'] }}">
                    @if (!empty($tpl['thumb']))
                        <img src="{{ $tpl['thumb'] }}" alt="" loading="lazy">
                    @else
                        <span class="be-noimg"></span>
                    @endif
                    <span>{{ $tpl['title'] }}</span>
                    <small>{{ $tpl['description'] }}</small>
                </button>
            @endforeach
        </div>
    </template>

    <script src="{{ \Juwri\Core\Support\ViewHelpers::editorAsset($gjsDir.'/grapes.min.js') }}" nonce="{{ csp_nonce() }}"></script>
    <script nonce="{{ csp_nonce() }}">
        (function () {
            'use strict';

            var CFG = {
                saveUrl: @json($builder['saveUrl']),
                publishUrl: @json($builder['publishUrl']),
                previewUrl: @json($builder['previewUrl']),
                assetsUrl: @json($builder['assetsUrl']),
                templateUrl: @json($builder['templateUrl']),
                csrf: @json(csrf_token()),
                base: @json($baseTemplate),
                design: @json($designData),
                html: @json((string) ($draftHtml ?? '')),
                canvasCss: @json(\Juwri\Core\Support\ViewHelpers::editorAsset('assets/builder/runtime/mb-builder.css')),
                locale: @json(in_array($locale, $gjsLocales, true) ? $locale : 'en'),
                localeUrl: @json(in_array($locale, $gjsLocales, true) ? \Juwri\Core\Support\ViewHelpers::editorAsset($gjsDir.'/locale/'.$locale.'.mjs') : null),
                engine: 'grapesjs@0.23.6',
                t: {
                    saving: @json(___('Saving…')),
                    saved: @json(___('Saved.')),
                    saveFailed: @json(___('Could not save. Please try again.')),
                    publishing: @json(___('Publishing…')),
                    published: @json(___('Published.')),
                    publishFailed: @json(___('Could not publish. Please try again.')),
                    templates: @json(___('Templates')),
                    templateFailed: @json(___('Could not load that template.')),
                    templateConfirm: @json(___('Switch to this template? Your current design on this page will be replaced.')),
                    uploadFailed: @json(___('Could not upload the file.')),
                    leave: @json(___('You have unsaved changes.')),
                    blocks: {
                        layout: @json(___('Layout')), basic: @json(___('Basic')), media: @json(___('Media')), interactive: @json(___('Interactive')),
                        section: @json(___('Section')), cols2: @json(___('Two columns')), cols3: @json(___('Three columns')),
                        heading: @json(___('Heading')), text: @json(___('Text')), button: @json(___('Button')), divider: @json(___('Divider')), spacer: @json(___('Spacer')), list: @json(___('List')), quote: @json(___('Quote')),
                        image: @json(___('Image')), video: @json(___('Video')), map: @json(___('Map')),
                        form: @json(___('Contact form')), accordion: @json(___('Accordion')), tabs: @json(___('Tabs')), carousel: @json(___('Carousel')), counter: @json(___('Counter')), reveal: @json(___('Reveal on scroll'))
                    }
                }
            };

            // ── Helpers ────────────────────────────────────────────────────────────
            function toast(message, isError) {
                var wrap = document.querySelector('.be-toasts');
                if (!wrap) {
                    wrap = document.createElement('div');
                    wrap.className = 'be-toasts';
                    wrap.setAttribute('role', 'status');
                    document.body.appendChild(wrap);
                }
                var el = document.createElement('div');
                el.className = 'be-toast' + (isError ? ' is-error' : '');
                el.textContent = message;
                wrap.appendChild(el);
                setTimeout(function () { el.remove(); }, 3200);
            }

            function busy(btn, on, label) {
                if (on) {
                    btn.dataset.beHtml = btn.innerHTML;
                    btn.disabled = true;
                    btn.textContent = label;
                } else {
                    btn.disabled = false;
                    if (btn.dataset.beHtml) { btn.innerHTML = btn.dataset.beHtml; delete btn.dataset.beHtml; }
                }
            }

            function post(url, fd) {
                fd.append('_token', CFG.csrf);
                return fetch(url, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CFG.csrf, Accept: 'application/json' }
                }).then(function (r) { return r.ok ? r.json() : Promise.reject(r); });
            }

            // A saved draft is sanitised HTML wrapped in <div class="mb-doc"> with every
            // CSS rule scoped under `.mb-doc` (Juwri\Core\Support\Html::cleanPage). Unwrap it so
            // the editor works on the author's own markup; cleanPage re-scopes on save.
            function unwrapDraft(html) {
                if (!html) { return ''; }
                var doc = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
                var body = doc.body;
                var root = body.children.length === 1 && body.firstElementChild.classList.contains('mb-doc') ? body.firstElementChild : body;
                root.querySelectorAll('style').forEach(function (st) {
                    st.removeAttribute('nonce');
                    st.textContent = st.textContent
                        .replace(/\.mb-doc(?=\s*[{,])/g, 'body')
                        .replace(/\.mb-doc\s+/g, '');
                });
                root.querySelectorAll('[data-mb-form]').forEach(function (f) { f.removeAttribute('data-mb-form'); });
                return root.innerHTML;
            }

            // ── CSS parser ─────────────────────────────────────────────────────────
            // GrapesJS's default parser reads CSS through the browser's CSSOM, which
            // expands shorthands into longhands and loses any shorthand that uses var()
            // (`background: var(--accent)` comes back empty). This textual parser keeps
            // every declaration exactly as written, so a saved page round-trips.
            function splitTop(text, sep) {
                var out = [], buf = '', depth = 0, quote = '';
                for (var i = 0; i < text.length; i++) {
                    var c = text[i];
                    if (quote) { buf += c; if (c === quote && text[i - 1] !== '\\') { quote = ''; } continue; }
                    if (c === '"' || c === "'") { quote = c; buf += c; continue; }
                    if (c === '(') { depth++; } else if (c === ')') { depth--; }
                    if (c === sep && depth === 0) { out.push(buf); buf = ''; continue; }
                    buf += c;
                }
                out.push(buf);
                return out;
            }
            function blocksOf(css) {
                var out = [], depth = 0, start = 0, head = '', quote = '';
                for (var i = 0; i < css.length; i++) {
                    var c = css[i];
                    if (quote) { if (c === quote && css[i - 1] !== '\\') { quote = ''; } continue; }
                    if (c === '"' || c === "'") { quote = c; continue; }
                    if (c === '{') { if (depth === 0) { head = css.slice(start, i).trim(); start = i + 1; } depth++; }
                    else if (c === '}') { depth--; if (depth === 0) { out.push([head, css.slice(start, i)]); start = i + 1; } }
                    else if (c === ';' && depth === 0) { start = i + 1; }   // drop @import/@charset statements
                }
                return out;
            }
            function declsOf(body) {
                var style = {};
                splitTop(body, ';').forEach(function (d) {
                    var at = d.indexOf(':');
                    if (at < 1) { return; }
                    var prop = d.slice(0, at).trim(), val = d.slice(at + 1).trim();
                    if (prop && val) { style[prop] = val; }
                });
                return style;
            }
            function parseCss(css, ctx) {
                var rules = [];
                ctx = ctx || {};
                blocksOf(String(css || '').replace(/\/\*[\s\S]*?\*\//g, '')).forEach(function (b) {
                    var head = b[0], body = b[1];
                    if (head.charAt(0) === '@') {
                        var m = head.match(/^@([\w-]+)\s*([\s\S]*)$/) || [];
                        var name = (m[1] || '').toLowerCase(), cond = (m[2] || '').trim();
                        if (name === 'media' || name === 'supports' || name === 'container') {
                            rules = rules.concat(parseCss(body, { atRuleType: name, mediaText: cond }));
                        } else if (/keyframes$/.test(name)) {
                            blocksOf(body).forEach(function (k) {
                                rules.push({ atRuleType: 'keyframes', mediaText: cond, selectors: [], selectorsAdd: k[0].trim(), style: declsOf(k[1]) });
                            });
                        } else if (name === 'font-face') {
                            rules.push({ atRuleType: 'font-face', singleAtRule: true, style: declsOf(body) });
                        }
                        return;
                    }
                    var rule = { style: declsOf(body) };
                    var simple = head.match(/^((?:\.[A-Za-z_][\w-]*)+)(?::(hover|focus|active|focus-visible))?$/);
                    if (simple) {
                        rule.selectors = simple[1].split('.').filter(Boolean);
                        if (simple[2]) { rule.state = simple[2]; }
                    } else {
                        rule.selectors = [];
                        rule.selectorsAdd = head;
                    }
                    if (ctx.atRuleType) { rule.atRuleType = ctx.atRuleType; rule.mediaText = ctx.mediaText; }
                    rules.push(rule);
                });
                return rules;
            }

            // ── Blocks (own markup; the data-mb-* hooks are wired by mb-builder.js) ──
            var B = CFG.t.blocks;
            var PH = @json(asset('assets/builder/templates/_shared/photo.svg'));
            var box = 'max-width:1040px;margin:0 auto;';
            var blocks = [
                { id: 'mb-section', label: B.section, category: B.layout, content: '<section style="padding:48px 20px;"><div style="' + box + '"><h2>' + B.section + '</h2><p>' + B.text + '</p></div></section>' },
                { id: 'mb-cols2', label: B.cols2, category: B.layout, content: '<section style="padding:32px 20px;"><div style="' + box + 'display:grid;gap:24px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));"><div><h3>' + B.heading + '</h3><p>' + B.text + '</p></div><div><h3>' + B.heading + '</h3><p>' + B.text + '</p></div></div></section>' },
                { id: 'mb-cols3', label: B.cols3, category: B.layout, content: '<section style="padding:32px 20px;"><div style="' + box + 'display:grid;gap:24px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));"><div><h3>' + B.heading + '</h3><p>' + B.text + '</p></div><div><h3>' + B.heading + '</h3><p>' + B.text + '</p></div><div><h3>' + B.heading + '</h3><p>' + B.text + '</p></div></div></section>' },
                { id: 'mb-heading', label: B.heading, category: B.basic, content: '<h2>' + B.heading + '</h2>' },
                { id: 'mb-text', label: B.text, category: B.basic, content: { type: 'text', content: B.text, style: { margin: '0 0 12px' } } },
                { id: 'mb-button', label: B.button, category: B.basic, content: { type: 'link', content: B.button, attributes: { href: '#' }, style: { display: 'inline-block', padding: '12px 24px', 'border-radius': '999px', background: '#4f46e5', color: '#ffffff', 'font-weight': '600', 'text-decoration': 'none' } } },
                { id: 'mb-list', label: B.list, category: B.basic, content: '<ul><li>' + B.text + '</li><li>' + B.text + '</li><li>' + B.text + '</li></ul>' },
                { id: 'mb-quote', label: B.quote, category: B.basic, content: '<blockquote style="margin:0;padding:16px 20px;border-inline-start:4px solid #4f46e5;font-size:20px;">' + B.text + '</blockquote>' },
                { id: 'mb-divider', label: B.divider, category: B.basic, content: '<hr style="border:0;border-top:1px solid #e6e3dc;margin:24px 0;">' },
                { id: 'mb-spacer', label: B.spacer, category: B.basic, content: '<div style="height:40px;"></div>' },
                { id: 'mb-image', label: B.image, category: B.media, activate: true, select: true, content: { type: 'image', attributes: { src: PH, alt: '' }, style: { 'max-width': '100%', height: 'auto', display: 'block' } } },
                { id: 'mb-video', label: B.video, category: B.media, content: '<div style="position:relative;aspect-ratio:16/9;"><iframe src="https://www.youtube-nocookie.com/embed/" title="' + B.video + '" style="position:absolute;inset:0;width:100%;height:100%;border:0;" allowfullscreen></iframe></div>' },
                { id: 'mb-map', label: B.map, category: B.media, content: '<div style="aspect-ratio:16/9;"><iframe src="https://maps.google.com/maps?q=&output=embed" title="' + B.map + '" style="width:100%;height:100%;border:0;"></iframe></div>' },
                { id: 'mb-form', label: B.form, category: B.interactive, content: '<form style="display:grid;gap:12px;max-width:520px;"><input type="text" name="name" placeholder="Name" required><input type="email" name="email" placeholder="Email" required><textarea name="message" rows="4" placeholder="Message"></textarea><button type="submit">Send</button></form>' },
                { id: 'mb-accordion', label: B.accordion, category: B.interactive, content: '<div data-mb-accordion="single"><div data-mb-accordion-item data-mb-open><div data-mb-accordion-trigger style="font-weight:600;padding:12px 0;">' + B.heading + '</div><div data-mb-accordion-panel><p>' + B.text + '</p></div></div><div data-mb-accordion-item><div data-mb-accordion-trigger style="font-weight:600;padding:12px 0;">' + B.heading + '</div><div data-mb-accordion-panel><p>' + B.text + '</p></div></div></div>' },
                { id: 'mb-tabs', label: B.tabs, category: B.interactive, content: '<div data-mb-tabs><div style="display:flex;gap:8px;"><span data-mb-tab>' + B.heading + ' 1</span><span data-mb-tab>' + B.heading + ' 2</span></div><div data-mb-tabpanel><p>' + B.text + ' 1</p></div><div data-mb-tabpanel><p>' + B.text + ' 2</p></div></div>' },
                { id: 'mb-carousel', label: B.carousel, category: B.interactive, content: '<div data-mb-carousel><div data-mb-carousel-track><img src="' + PH + '" alt="" style="width:80%;border-radius:12px;"><img src="' + PH + '" alt="" style="width:80%;border-radius:12px;"><img src="' + PH + '" alt="" style="width:80%;border-radius:12px;"></div><div data-mb-carousel-dots></div></div>' },
                { id: 'mb-counter', label: B.counter, category: B.interactive, content: '<div style="text-align:center;"><div data-mb-count-up style="font-size:44px;font-weight:700;">1200</div><p>' + B.text + '</p></div>' },
                { id: 'mb-reveal', label: B.reveal, category: B.interactive, content: '<div data-mb-reveal="up" style="padding:24px;"><h3>' + B.heading + '</h3><p>' + B.text + '</p></div>' }
            ];

            // ── Editor ────────────────────────────────────────────────────────────
            function start(messages) {

                var editor = window.grapesjs.init({
                    container: '#gjs',
                    height: '100%',
                    fromElement: false,
                    storageManager: false,
                    protectedCss: '',
                    // Keep rules GrapesJS can't match to a component (descendant/pseudo selectors,
                    // @media groups) — they are part of the author's stylesheet.
                    keepUnusedStyles: true,
                    i18n: { detectLocale: false },
                    parser: { parserCss: function (css) { return parseCss(css); } },
                    canvas: { styles: [CFG.canvasCss] },
                    deviceManager: {
                        devices: [
                            { id: 'Desktop', name: 'Desktop', width: '' },
                            { id: 'Tablet', name: 'Tablet', width: '770px', widthMedia: '992px' },
                            { id: 'Mobile portrait', name: 'Mobile portrait', width: '375px', widthMedia: '480px' }
                        ]
                    },
                    blockManager: { blocks: blocks },
                    assetManager: {
                        upload: false,
                        uploadFile: function (e) {
                            var files = (e.dataTransfer || e.target).files;
                            Array.prototype.forEach.call(files || [], function (file) {
                                var fd = new FormData();
                                fd.append('file', file);
                                fd.append('kind', 'image');
                                post(CFG.assetsUrl, fd)
                                    .then(function (d) {
                                        if (d && d.url) { editor.AssetManager.add({ src: d.url }); } else { toast(CFG.t.uploadFailed, true); }
                                    })
                                    .catch(function () { toast(CFG.t.uploadFailed, true); });
                            });
                        }
                    }
                });
                window.editor = editor;
                if (messages) {
                    var add = {};
                    add[CFG.locale] = messages;
                    editor.I18n.addMessages(add);
                    editor.I18n.setLocale(CFG.locale);
                }

                var project = CFG.design && typeof CFG.design === 'object' && CFG.design.gjs ? CFG.design.project : null;
                if (project) {
                    editor.loadProjectData(project);
                } else {
                    editor.setComponents(unwrapDraft(CFG.html));
                }

                editor.on('load', function () {
                    var body = editor.Canvas.getBody();
                    if (body) { body.classList.add('mb-doc'); }
                    editor.UndoManager.clear();
                    editor.clearDirtyCount();
                    var blocksBtn = editor.Panels.getButton('views', 'open-blocks');
                    if (blocksBtn) { blocksBtn.set('active', true); }
                });

                // Unsaved = GrapesJS's own change counter (reset on load, save and template switch).
                var leaving = false;
                window.addEventListener('beforeunload', function (e) {
                    if (!leaving && editor.getDirtyCount() > 0) { e.preventDefault(); e.returnValue = CFG.t.leave; }
                });

                // ── Save / publish ──
                function payload() {
                    var fd = new FormData();
                    var css = editor.getCss() || '';
                    var html = editor.getWrapper().getInnerHTML();
                    fd.append('html', (css.trim() !== '' ? '<style>' + css + '</style>' : '') + html);
                    fd.append('design', JSON.stringify({ gjs: CFG.engine, project: editor.getProjectData() }));
                    fd.append('base', CFG.base);
                    fd.append('_method', 'PUT');
                    return fd;
                }

                function save() {
                    return post(CFG.saveUrl, payload()).then(function (d) {
                        if (!d || !d.ok) { return Promise.reject(d); }
                        editor.clearDirtyCount();
                        return d;
                    });
                }

                var saveBtn = document.getElementById('beSave');
                saveBtn.addEventListener('click', function () {
                    busy(saveBtn, true, CFG.t.saving);
                    save().then(function () { toast(CFG.t.saved); })
                        .catch(function () { toast(CFG.t.saveFailed, true); })
                        .finally(function () { busy(saveBtn, false); });
                });

                var pubBtn = document.getElementById('bePublish');
                pubBtn.addEventListener('click', function () {
                    busy(pubBtn, true, CFG.t.publishing);
                    save().then(function () { return post(CFG.publishUrl, new FormData()); })
                        .then(function (d) { if (d && d.ok) { toast(CFG.t.published); } else { return Promise.reject(d); } })
                        .catch(function () { toast(CFG.t.publishFailed, true); })
                        .finally(function () { busy(pubBtn, false); });
                });

                document.getElementById('bePreview').addEventListener('click', function () {
                    window.open(CFG.previewUrl, '_blank', 'noopener');
                });

                // ── Devices, undo/redo ──
                document.querySelectorAll('[data-device]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        editor.setDevice(btn.getAttribute('data-device'));
                        document.querySelectorAll('[data-device]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
                    });
                });
                document.getElementById('beUndo').addEventListener('click', function () { editor.UndoManager.undo(); });
                document.getElementById('beRedo').addEventListener('click', function () { editor.UndoManager.redo(); });

                // ── Template gallery: reset the draft to a template, then reload ──
                document.getElementById('beTemplates').addEventListener('click', function () {
                    var wrap = document.createElement('div');
                    wrap.appendChild(document.getElementById('beGallery').content.cloneNode(true));
                    wrap.addEventListener('click', function (e) {
                        var card = e.target.closest('[data-tpl-id]');
                        if (!card || !window.confirm(CFG.t.templateConfirm)) { return; }
                        var fd = new FormData();
                        fd.append('template', card.getAttribute('data-tpl-id'));
                        post(CFG.templateUrl, fd)
                            .then(function (d) {
                                if (!d || !d.ok) { return Promise.reject(d); }
                                leaving = true;
                                window.location.reload();
                            })
                            .catch(function () { toast(CFG.t.templateFailed, true); });
                    });
                    editor.Modal.open({ title: CFG.t.templates, content: wrap });
                });
            }

            if (CFG.localeUrl) {
                import(CFG.localeUrl).then(function (m) { start(m.default); }).catch(function () { start(null); });
            } else {
                start(null);
            }
        })();
    </script>
</body>

</html>
