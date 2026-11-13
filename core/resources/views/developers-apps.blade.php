{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- stores P5-1: public app-developer documentation. Scopes + webhook events +
     session TTL are reflected from code (DeveloperDocsController@apps), so this
     reference cannot drift from what an app can really do.

     EXTENDS the marketing frame (marketing.layout) so it carries the exact site
     nav + footer + fonts + theme toggle + language picker. Doc content is
     namespaced under a scoped `.dev-docs` wrapper. --}}
@extends('marketing.layout')

@section('title', $siteName.' — App developer docs')

@push('styles')
<style nonce="{{ csp_nonce() }}">
    .dev-docs { --dd-text:#0a0910; --dd-muted:#6c6b70; --dd-acc:#8b3dff; --dd-panel:#f5f4f7; --dd-border:#e7e6ea; color:var(--dd-text); }
    :root[data-theme="dark"] .dev-docs { --dd-text:#e6edf3; --dd-muted:#9aa0a6; --dd-panel:#161b22; --dd-border:#2a2f37; }
    .dev-docs a { color:var(--dd-acc); text-decoration:none; } .dev-docs a:hover { text-decoration:underline; }
    .dev-docs .layout { display:grid; grid-template-columns:220px minmax(0, 1fr); gap:40px; max-width:1100px; margin:0 auto; padding:32px 24px 80px; }
    .dev-docs .dd-main { min-width:0; }
    .dev-docs .dd-main :is(p, li, h2, h3) { overflow-wrap:anywhere; }
    @media (max-width:800px){ .dev-docs .layout{ grid-template-columns:1fr; } .dev-docs nav.toc{ display:none; } }
    .dev-docs nav.toc { position:sticky; top:100px; align-self:start; font-size:14px; }
    .dev-docs nav.toc a { display:block; color:var(--dd-muted); padding:5px 0; padding-inline-start:12px; border-inline-start:2px solid transparent; }
    .dev-docs nav.toc a:hover { color:var(--dd-text); border-color:var(--dd-acc); text-decoration:none; }
    .dev-docs section { margin-bottom:48px; scroll-margin-top:100px; }
    .dev-docs h1 { font-size:30px; margin:0 0 8px; color:var(--dd-text); }
    .dev-docs h2 { font-size:24px; margin:0 0 12px; color:var(--dd-text); }
    .dev-docs h3 { font-size:17px; margin:24px 0 8px; color:var(--dd-text); }
    .dev-docs p, .dev-docs li { color:var(--dd-muted); }
    .dev-docs code { background:var(--dd-panel); border:1px solid var(--dd-border); border-radius:6px; padding:1px 6px; font-size:13.5px; color:var(--dd-text); }
    .dev-docs pre { background:var(--dd-panel); border:1px solid var(--dd-border); border-radius:10px; padding:16px; overflow-x:auto; font-size:13.5px; line-height:1.55; color:var(--dd-text); }
    .dev-docs pre code { background:none; border:0; padding:0; }
    .dev-docs table { border-collapse:collapse; width:100%; font-size:14px; margin:10px 0 4px; }
    .dev-docs th, .dev-docs td { text-align:start; padding:7px 10px; border-bottom:1px solid var(--dd-border); }
    .dev-docs th { color:var(--dd-muted); font-weight:600; }
    .dev-docs .step { border-inline-start:2px solid var(--dd-border); padding:2px 0; padding-inline-start:18px; margin:0 0 8px; }
    .dev-docs .lead { color:var(--dd-muted); max-width:70ch; }
</style>
@endpush

@section('content')
<div class="dev-docs">
<div class="layout">
    <nav class="toc">
        <a href="#start">{{ ___('Getting started') }}</a>
        <a href="#oauth">{{ ___('OAuth & tokens') }}</a>
        <a href="#session">{{ ___('Session tokens') }}</a>
        <a href="#embed">{{ ___('Embedded apps') }}</a>
        <a href="#webhooks">{{ ___('Webhooks') }}</a>
        <a href="#usage">{{ ___('Usage billing') }}</a>
        <a href="#howto">{{ ___('How-to guides') }}</a>
        <a href="#scopes">{{ ___('Scopes reference') }}</a>
        <a href="#events">{{ ___('Webhook events') }}</a>
        <a href="#requirements">{{ ___('Listing requirements') }}</a>
    </nav>

    <div class="dd-main">
        <section id="start">
            <h1>{{ ___('Build an app') }}</h1>
            <p class="lead">{{ ___('Apps extend a restaurant\'s account through scoped, revocable OAuth access. Your code runs on your servers; the platform holds the registry, the install ledger, and the billing rails. This guide takes you from zero to an installed, metered app.') }}</p>
            <h3>{{ ___('1. Register your app') }}</h3>
            <p class="step">{{ ___('Create an app in the') }} @if ($partnerUrl)<a href="{{ $partnerUrl }}">{{ ___('partner dashboard') }}</a>@else{{ ___('partner dashboard') }}@endif: {{ ___('name, category, the scopes you need, an optional webhook URL, and your billing model. Submitting mints an OAuth client (client id + secret) and puts the app in review. Once an admin approves it, it appears in the App Store and can be installed.') }}</p>
            <h3>{{ ___('2. Get installed') }}</h3>
            <p class="step">{{ ___('A restaurant owner installs your app from its App Store detail page, granting the scopes you requested. Installation creates an') }} <code>AppInstallation</code> {{ ___('and (for paid apps) charges the first period through their wallet.') }}</p>
            <h3>{{ ___('3. Call the API') }}</h3>
            <p class="step">{{ ___('Exchange an OAuth token for the installing account, then call the') }} <a href="{{ route('developers') }}">{{ ___('REST API') }}</a> {{ ___('with it. Report usage, receive webhooks, or embed a page in their dashboard — covered below.') }}</p>
        </section>

        <section id="oauth">
            <h2>{{ ___('OAuth & tokens') }}</h2>
            <p>{{ ___('Apps authenticate with OAuth 2.0 (Laravel Passport). Your client credentials come from the partner dashboard. Use the standard authorization-code flow; the granted token carries the scopes the owner approved at install.') }}</p>
            <pre><code># Exchange an authorization code for an access token
curl -X POST {{ $appUrl }}oauth/token \
  -d grant_type=authorization_code \
  -d client_id=YOUR_CLIENT_ID \
  -d client_secret=YOUR_CLIENT_SECRET \
  -d redirect_uri=YOUR_REDIRECT \
  -d code=AUTH_CODE

# Call the API with the returned bearer token
curl {{ $baseUrl }}/restaurants \
  -H "Authorization: Bearer ACCESS_TOKEN" \
  -H "Accept: application/json"</code></pre>
            <p>{{ ___('Tokens are scoped: a call needing') }} <code>orders:read</code> {{ ___('fails unless the owner granted it. Request the minimum set — reviewers check this.') }}</p>
        </section>

        <section id="session">
            <h2>{{ ___('Session tokens') }}</h2>
            <p>{{ ___('For short-lived, server-to-embed calls, mint a session token from an active installation. Tokens expire after') }} <code>{{ $sessionTtl }}</code> {{ ___('seconds.') }}</p>
            <pre><code>GET {{ $appUrl }}apps/{appId}/session-token
# → { "token": "…", "expires_in": {{ $sessionTtl }} }</code></pre>
        </section>

        <section id="embed">
            <h2>{{ ___('Embedded apps') }}</h2>
            <p>{{ ___('If your app declares an') }} <code>embed_url</code>, {{ ___('the platform hosts it in an iframe inside the owner\'s dashboard at') }} <code>/apps/{appId}/embed</code>. {{ ___('Pair it with a session token (above) to authenticate the embedded page without a full OAuth round-trip.') }}</p>
        </section>

        <section id="webhooks">
            <h2>{{ ___('Webhooks') }}</h2>
            <p>{{ ___('Subscribe to events at your app\'s') }} <code>webhook_url</code>. {{ ___('Deliveries are queued, retried, and signed with the') }} <a href="https://www.standardwebhooks.com" rel="noopener">{{ ___('Standard Webhooks') }}</a> {{ ___('scheme using your app\'s signing secret. Your endpoint must be a public HTTPS URL (no private/loopback/metadata hosts) and return') }} <code>2xx</code> {{ ___('quickly.') }}</p>
            <pre><code># A delivery your endpoint receives
POST https://your-app.com/webhooks
webhook-id: msg_...
webhook-timestamp: 1710000000
webhook-signature: v1,BASE64_HMAC
Content-Type: application/json

{ "type": "order.paid", "data": { "order_id": 123, ... } }</code></pre>
            <p>{{ ___('Verify the signature against your secret before trusting a payload. See') }} <a href="#events">{{ ___('the event list') }}</a>.</p>
        </section>

        <section id="usage">
            <h2>{{ ___('Usage billing') }}</h2>
            <p>{{ ___('Usage-billed apps meter consumption by reporting units. Each report is charged to the owner\'s wallet at your per-unit price. Pass a unique') }} <code>ref</code> {{ ___('to make a report idempotent (a repeat with the same ref is ignored).') }}</p>
            <pre><code>curl -X POST {{ $usageUrl }} \
  -H "Authorization: Bearer ACCESS_TOKEN" \
  -H "Accept: application/json" \
  -d quantity=1 \
  -d ref=unique-key-per-event</code></pre>
            <table>
                <tr><th>{{ ___('Field') }}</th><th>{{ ___('Type') }}</th><th>{{ ___('Notes') }}</th></tr>
                <tr><td><code>quantity</code></td><td>{{ ___('number > 0') }}</td><td>{{ ___('required — units consumed') }}</td></tr>
                <tr><td><code>ref</code></td><td>{{ ___('string ≤ 120') }}</td><td>{{ ___('optional idempotency key') }}</td></tr>
            </table>
            <p>{{ ___('The endpoint returns') }} <code>403</code> {{ ___('if the token doesn\'t belong to a registered app or the app isn\'t installed for the account.') }}</p>
        </section>

        <section id="howto">
            <h2>{{ ___('How-to guides') }}</h2>
            <h3>{{ ___('Choose a billing type') }}</h3>
            <ul>
                <li><b>{{ ___('Free') }}</b> — {{ ___('no charge on install.') }}</li>
                <li><b>{{ ___('Recurring') }}</b> — {{ ___('a fixed price charged every billing period (default 30 days) from the owner\'s wallet.') }}</li>
                <li><b>{{ ___('Usage-based') }}</b> — {{ ___('a per-unit price billed as you report usage.') }}</li>
            </ul>
            <h3>{{ ___('Request scopes responsibly') }}</h3>
            <p>{{ ___('Only request scopes your app uses. Changing scopes on a live listing sends the app back to review. See the') }} <a href="#scopes">{{ ___('scopes reference') }}</a>.</p>
            <h3>{{ ___('Clean up on uninstall') }}</h3>
            <p>{{ ___('When an owner uninstalls, the platform revokes the app\'s tokens and fires an') }} <code>app/uninstalled</code> {{ ___('event. Stop background work and delete stored data for that account when you receive it.') }}</p>
        </section>

        <section id="scopes">
            <h2>{{ ___('Scopes reference') }}</h2>
            <p>{{ ___('Reflected from the platform\'s OAuth scope registry.') }}</p>
            <table>
                <tr><th>{{ ___('Scope') }}</th><th>{{ ___('Grants') }}</th></tr>
                @foreach($scopes as $s)
                    <tr><td><code>{{ $s['id'] }}</code></td><td>{{ $s['desc'] }}</td></tr>
                @endforeach
            </table>
        </section>

        <section id="events">
            <h2>{{ ___('Webhook events') }}</h2>
            <p>{{ ___('Events you can subscribe to today:') }}</p>
            <table>
                <tr><th>{{ ___('Event') }}</th></tr>
                @foreach($events as $e)
                    <tr><td><code>{{ $e }}</code></td></tr>
                @endforeach
            </table>
        </section>

        <section id="requirements">
            <h2>{{ ___('Listing & review requirements') }}</h2>
            <p>{{ ___('Before an admin approves your app, it must:') }}</p>
            <ul>
                <li>{{ ___('Request only the scopes it uses, each justified in the description.') }}</li>
                <li>{{ ___('Provide a working webhook endpoint (public HTTPS) if it subscribes to events.') }}</li>
                <li>{{ ___('Declare accurate pricing — the pricing table on your detail page is generated from it.') }}</li>
                <li>{{ ___('Include a clear tagline, description, category, and at least one screenshot.') }}</li>
                <li>{{ ___('Handle uninstall cleanly (revoke access, stop billing, delete account data).') }}</li>
            </ul>
            <p>{{ ___('Metadata edits (tagline, description, screenshots, links) go live immediately; price or scope changes re-queue review.') }}</p>
        </section>
    </div>
</div>
</div>
@endsection
