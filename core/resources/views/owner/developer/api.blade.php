{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('API & Webhooks'))
@section('page_title', ___('API tokens & webhooks'))
@section('page_sub', ___('Programmatic access to your stores, menu, and orders.'))

@section('content')
@php $impBlocked = \Juwri\Core\Support\ImpersonationRoutes::blocksNow('api.webhook.create'); @endphp
<div class="ui-scope ui-api">
    @if($impBlocked)
        <x-ui.banner tone="warning">{{ ___('These actions are disabled during a support session. Ask the account owner to perform them, or exit impersonation first.') }}</x-ui.banner>
    @endif

    {{-- P6-75: integration tokens survive a password reset/change or 2FA disable; the owner decides. --}}
    @if (($reviewTokens ?? collect())->isNotEmpty())
        <x-ui.banner tone="warning">
            <p><strong>{{ ___('Your sign-in details changed. These API tokens still work:') }}</strong></p>
            <ul>
                @foreach ($reviewTokens as $rt)
                    <li>{{ $rt->name }} <span class="ui-muted ui-small">· {{ $rt->last_used_at ? ___('last used :when', ['when' => $rt->last_used_at->diffForHumans()]) : ___('Never') }}</span></li>
                @endforeach
            </ul>
            <p>{{ ___('If you did not make this change, or do not recognise a token, revoke it now.') }}</p>
            <div class="ui-api__links">
                <form action="{{ route('api.token.review.revoke') }}" method="post" data-confirm="{{ ___('Revoke all of these tokens? Integrations using them will stop working.') }}" data-confirm-danger>
                    @csrf
                    <x-ui.button type="submit" variant="danger" size="sm" icon="trash" :disabled="$impBlocked">{{ ___('Revoke all') }}</x-ui.button>
                </form>
                <form action="{{ route('api.token.review.dismiss') }}" method="post">
                    @csrf
                    <x-ui.button type="submit" variant="secondary" size="sm" :disabled="$impBlocked">{{ ___('Keep them') }}</x-ui.button>
                </form>
            </div>
        </x-ui.banner>
    @endif

    <x-ui.card class="ui-api__hero">
        <div class="ui-api__hero-text">
            <p class="ui-overline">{{ ___('Developers') }}</p>
            <h2 class="ui-api__title">{{ ___('Build integrations') }}</h2>
            <p class="ui-api__sub">{{ ___('Issue scoped tokens. Subscribe to webhook events. Inspect delivery logs.') }}</p>
            <div class="ui-api__links">
                <x-ui.button :href="route('developers')" target="_blank" rel="noopener" variant="secondary" size="sm" icon="book-open">{{ ___('API documentation') }}</x-ui.button>
                <x-ui.button :href="url('/api/v1')" target="_blank" rel="noopener" variant="secondary" size="sm" icon="external">{{ ___('Base URL') }}</x-ui.button>
                {{-- HPMS E11: request-log viewer --}}
                <x-ui.button :href="route('api.requestLogs')" variant="secondary" size="sm" icon="list">{{ ___('Request logs') }}</x-ui.button>
            </div>
        </div>
        <div class="ui-api__stats">
            <x-ui.stat :value="$tokens->count()" :label="___('Tokens')" icon="settings" />
            <x-ui.stat :value="$webhooks->count()" :label="___('Webhooks')" icon="share" />
            <x-ui.stat value="120/min" :label="___('Rate limit')" icon="clock" class="ui-api__rate" />
        </div>
    </x-ui.card>

    @if (! empty($newToken))
        <x-ui.banner tone="success">
            <strong>{{ ___('Copy this token now - it will not be shown again.') }}</strong>
            <code class="ui-api__secret">{{ $newToken }}</code>
        </x-ui.banner>
    @endif

    <x-ui.card :title="___('Create token')">
        <form action="{{ route('api.token.create') }}" method="post" class="ui-api__form">
            @csrf
            <div class="ui-api__fields">
                <div>
                    <label class="ui-label" for="api-token-name">{{ ___('Name') }}</label>
                    <input id="api-token-name" type="text" name="name" class="ui-input" required maxlength="80" placeholder="POS integration">
                </div>
                <div>
                    <label class="ui-label" for="api-token-abilities">{{ ___('Abilities') }}</label>
                    <select id="api-token-abilities" name="abilities[]" class="ui-input" multiple size="6">
                        <optgroup label="{{ ___('All resources') }}">
                            <option value="read" selected>read (all)</option>
                            <option value="write">write (all)</option>
                        </optgroup>
                        <optgroup label="{{ ___('Scoped (resource:action)') }}">
                            @foreach (\Juwri\Modules\Integrations\Models\ApiToken::RESOURCES as $res)
                                <option value="{{ $res }}:read">{{ $res }}:read</option>
                                <option value="{{ $res }}:write">{{ $res }}:write</option>
                            @endforeach
                        </optgroup>
                    </select>
                    <small class="ui-api__hint">{{ ___('Hold Cmd/Ctrl to pick multiple. Scoped tokens limit access to one resource, e.g. orders:write.') }}</small>
                </div>
                <div>
                    <label class="ui-label" for="api-token-expires">{{ ___('Expires in days') }}</label>
                    <input id="api-token-expires" type="number" name="expires_days" class="ui-input" min="1" max="3650" placeholder="365" inputmode="numeric">
                </div>
            </div>
            <div class="ui-api__submit">
                <x-ui.button type="submit" icon="plus" :disabled="$impBlocked">{{ ___('Create token') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{-- D3 (integrations pack): one-click "Connect" for the automation tools. Each
         button mints a pre-scoped token (webhooks:write + orders:read + menu:read)
         through the SAME password.confirm-protected create flow above, so the
         copy-once banner and impersonation guard apply unchanged. --}}
    <x-ui.card :title="___('Connect an automation tool')" data-testid="connect-tools">
        <p class="ui-api__sub">{{ ___('Mint a ready-scoped token for a no-code platform. It can read your orders and menu and manage webhooks — nothing else.') }}</p>
        @php
            $connectTools = [
                'zapier' => 'Zapier',
                'make'   => 'Make',
                'n8n'    => 'n8n',
            ];
        @endphp
        <div class="ui-api__tools">
            @foreach ($connectTools as $slug => $name)
                <div class="ui-api__tool" data-connect="{{ $slug }}">
                    <strong class="ui-api__tool-name">{{ $name }}</strong>
                    <a href="{{ route('integrations.docs', $slug) }}" class="ui-api__link" target="_blank" rel="noopener">{{ ___('Setup guide') }} <x-ui.icon name="external" size="14" /></a>
                    <form action="{{ route('api.token.create') }}" method="post">
                        @csrf
                        <input type="hidden" name="name" value="{{ $name }} connection">
                        <input type="hidden" name="abilities[]" value="webhooks:write">
                        <input type="hidden" name="abilities[]" value="orders:read">
                        <input type="hidden" name="abilities[]" value="menu:read">
                        <x-ui.button type="submit" variant="secondary" size="sm" icon="share" :disabled="$impBlocked">{{ ___('Connect') }}</x-ui.button>
                    </form>
                </div>
            @endforeach
        </div>
    </x-ui.card>

    {{-- #102: MCP server info --}}
    <x-ui.card :title="___('MCP server')">
        <x-slot:actions><x-ui.tag tone="accent">{{ ___('AI-operable') }}</x-ui.tag></x-slot:actions>
        <p class="ui-api__sub">{{ ___('Connect an MCP-compatible AI client (Claude, etc.) to operate your store in natural language. It uses the same API tokens below.') }}</p>
        <div class="ui-api__fields ui-api__fields--2">
            <div>
                <label class="ui-label" for="api-mcp-endpoint">{{ ___('Endpoint (JSON-RPC 2.0)') }}</label>
                <input id="api-mcp-endpoint" type="text" class="ui-input ui-ltr" readonly value="{{ url('/mcp') }}" data-ui-do="select">
            </div>
            <div>
                <label class="ui-label" for="api-mcp-auth">{{ ___('Auth') }}</label>
                <input id="api-mcp-auth" type="text" class="ui-input ui-ltr" readonly value="Authorization: Bearer <token>">
            </div>
        </div>
        <p class="ui-api__hint">
            {{ ___('Read tools (list_stores, get_menu, list_orders, get_analytics) work with a read token. Mutating tools (update_order_status, add/update_menu_item) require a token with the write ability. All calls are scoped to your stores and rate-limited.') }}
        </p>
    </x-ui.card>

    <x-ui.card :title="___('Tokens')" variant="flush">
        @if ($tokens->isEmpty())
            <x-ui.empty icon="settings" :title="___('No API tokens yet.')" />
        @else
            <table class="ui-table">
                <thead><tr><th>{{ ___('Name') }}</th><th>{{ ___('Abilities') }}</th><th>{{ ___('Expires') }}</th><th>{{ ___('Last used') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th></tr></thead>
                <tbody>
                    @foreach ($tokens as $t)
                        <tr>
                            <td data-primary><strong>{{ $t->name }}</strong></td>
                            <td data-label="{{ ___('Abilities') }}">
                                <span class="ui-api__tags">
                                    @foreach (($t->abilities ?? []) as $ab)
                                        <x-ui.tag tone="accent" class="ui-ltr">{{ $ab }}</x-ui.tag>
                                    @endforeach
                                </span>
                            </td>
                            <td data-label="{{ ___('Expires') }}"><span class="ui-ltr">{{ $t->expires_at ? $t->expires_at->format('Y-m-d') : ___('Never') }}</span></td>
                            <td data-label="{{ ___('Last used') }}">{{ $t->last_used_at ? $t->last_used_at->diffForHumans() : ___('Never') }}</td>
                            <td class="ui-catalog__row-actions">
                                <form action="{{ route('api.token.delete', $t->id) }}" method="post" data-confirm="{{ ___('Revoke this token?') }}" data-confirm-danger>
                                    @csrf @method('DELETE')
                                    <x-ui.button type="submit" variant="danger" size="sm" icon="trash" :disabled="$impBlocked">{{ ___('Revoke') }}</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>

    {{-- Storefront tokens (public, per-restaurant) — headless / AI agents --}}
    @if (! empty($newStorefrontToken))
        <x-ui.banner tone="success">
            <strong>{{ ___('Copy this storefront token now - it will not be shown again.') }}</strong>
            <code class="ui-api__secret">{{ $newStorefrontToken }}</code>
        </x-ui.banner>
    @endif

    <x-ui.card :title="___('Storefront tokens')">
        <x-slot:actions><x-ui.tag tone="accent">{{ ___('Public / headless') }}</x-ui.tag></x-slot:actions>
        <p class="ui-api__sub">{{ ___('Per-store public tokens for a headless storefront or AI agents. menu:read is safe to embed in a client; order:write and agent are more powerful.') }}</p>
        @if($stores->isEmpty())
            <x-ui.empty icon="store" :title="___('Create a store first.')" />
        @else
        <form action="{{ route('storefront.token.create') }}" method="post" class="ui-api__form">
            @csrf
            <div class="ui-api__fields">
                <div>
                    <label class="ui-label" for="sft-store">{{ ___('Store') }}</label>
                    <select id="sft-store" name="store_id" class="ui-input" required>
                        @foreach($stores as $r)<option value="{{ $r->id }}" @selected(($activeId ?? 0) === $r->id)>{{ $r->title }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label" for="sft-scopes">{{ ___('Scopes') }}</label>
                    <select id="sft-scopes" name="scopes[]" class="ui-input" multiple size="3">
                        @foreach(\App\Models\StorefrontToken::SCOPES as $s)<option value="{{ $s }}" @selected($s==='menu:read')>{{ $s }}</option>@endforeach
                    </select>
                    <small class="ui-api__hint">{{ ___('menu:read = browse · order:write = place orders · agent = MCP. Hold Cmd/Ctrl to multi-select.') }}</small>
                </div>
                <div>
                    <label class="ui-label" for="sft-label">{{ ___('Label (optional)') }}</label>
                    <input id="sft-label" type="text" name="label" class="ui-input" maxlength="80" placeholder="Website">
                </div>
            </div>
            <div class="ui-api__fields ui-api__fields--origins">
                <div>
                    <label class="ui-label" for="sft-origins">{{ ___('Allowed origins') }} <span class="ui-api__muted">({{ ___('one per line') }})</span></label>
                    <textarea id="sft-origins" name="allowed_origins" class="ui-input" @error('allowed_origins') aria-invalid="true" @enderror rows="2" placeholder="https://yourstore.com">{{ old('allowed_origins') }}</textarea>
                    <small class="ui-api__hint">{{ ___('Restrict order:write / agent requests to these browser origins. Required for a browser token; leave empty for a server-to-server token.') }}</small>
                    @error('allowed_origins')<p class="ui-api__error">{{ $message }}</p>@enderror
                </div>
                <div class="ui-api__browser">
                    <input type="hidden" name="browser" value="0">
                    <x-ui.switch name="browser" id="sft-browser" :checked="(bool) old('browser')" :label="___('Browser token')" :description="___('Embedded in front-end JS — origin binding enforced.')" />
                </div>
            </div>
            <div class="ui-api__submit">
                <x-ui.button type="submit" icon="plus" :disabled="$impBlocked">{{ ___('Create storefront token') }}</x-ui.button>
            </div>
        </form>
        @endif

        @if($storefrontTokens->isNotEmpty())
        <div class="ui-api__table-wrap">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Store') }}</th><th>{{ ___('Label') }}</th><th>{{ ___('Scopes') }}</th><th>{{ ___('Last used') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th></tr></thead>
                <tbody>
                    @foreach($storefrontTokens as $st)
                    <tr>
                        <td data-primary><strong>{{ $st->store->title ?? '—' }}</strong></td>
                        <td data-label="{{ ___('Label') }}">{{ $st->label ?: '—' }}</td>
                        <td data-label="{{ ___('Scopes') }}"><span class="ui-api__tags">@foreach(($st->scopes ?? []) as $sc)<x-ui.tag tone="accent" class="ui-ltr">{{ $sc }}</x-ui.tag>@endforeach @if($st->browser)<x-ui.tag tone="warning" :title="implode(', ', $st->allowed_origins ?? [])">{{ ___('browser') }}</x-ui.tag>@endif</span></td>
                        <td data-label="{{ ___('Last used') }}">{{ $st->last_used_at ? $st->last_used_at->diffForHumans() : ___('Never') }}</td>
                        <td class="ui-catalog__row-actions">
                            <form action="{{ route('storefront.token.delete', $st->id) }}" method="post" data-confirm="{{ ___('Revoke this storefront token?') }}" data-confirm-danger>
                                @csrf @method('DELETE')
                                <x-ui.button type="submit" variant="danger" size="sm" icon="trash" :disabled="$impBlocked">{{ ___('Revoke') }}</x-ui.button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </x-ui.card>

    {{-- D4 (integrations pack): dead-endpoint banner. An endpoint that auto-disabled
         (15 consecutive failures) reads as "Paused" otherwise — surface it loudly so
         the owner knows deliveries have stopped and why. --}}
    @php $autoDisabled = $webhooks->filter(fn ($w) => ! $w->active && $w->disabled_reason); @endphp
    @if ($autoDisabled->isNotEmpty())
        <x-ui.banner tone="danger" data-testid="dead-endpoint-banner">
            <strong>{{ ___('A webhook stopped delivering') }}</strong>
            {{ ___('One or more endpoints were auto-disabled after too many failures. Fix the URL and re-enable them below.') }}
        </x-ui.banner>
    @endif

    <x-ui.card :title="___('Webhook endpoints')" variant="flush">
        <form action="{{ route('api.webhook.create') }}" method="post" class="ui-api__form ui-api__pad">
            @csrf
            <div class="ui-api__fields ui-api__fields--2">
                <div>
                    <label class="ui-label" for="wh-url">{{ ___('URL') }}</label>
                    <input id="wh-url" type="url" name="url" class="ui-input ui-ltr" required placeholder="https://example.com/hook">
                </div>
                <div>
                    <label class="ui-label" for="wh-events">{{ ___('Events') }}</label>
                    {{-- A4: driven off WebhookEndpoint::SELECTABLE_EVENTS so every fired
                         topic (subscription.*, app.uninstalled, `*`) is subscribable.
                         D4: grouped into optgroups so a long list stays scannable. --}}
                    @php
                        $eventGroups = [
                            ___('All') => ['*'],
                            ___('Orders') => ['order.created', 'order.status_changed', 'order.paid', 'refund.completed'],
                            ___('Reservations & customers') => ['reservation.created', 'reservation.cancelled', 'customer.created'],
                            ___('Shifts') => ['shift.opened', 'shift.closed'],
                            ___('Menu') => ['menu.updated'],
                            ___('Billing & subscription') => ['entitlement.changed', 'subscription.paused', 'subscription.resumed', 'subscription.renewed', 'subscription.expired', 'subscription.plan_changed', 'subscription.past_due', 'subscription.expiring', 'subscription.trial_ending'],
                            ___('System') => ['app.uninstalled'],
                        ];
                    @endphp
                    <select id="wh-events" name="events[]" class="ui-input" multiple required size="8">
                        @foreach ($eventGroups as $label => $evs)
                            <optgroup label="{{ $label }}">
                                @foreach ($evs as $ev)
                                    <option value="{{ $ev }}" @selected($ev === 'order.created')>{{ $ev === '*' ? ___('* (all events)') : $ev }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="ui-api__submit">
                <x-ui.button type="submit" icon="plus" :disabled="$impBlocked">{{ ___('Add endpoint') }}</x-ui.button>
            </div>
        </form>
        @if ($webhooks->isNotEmpty())
            <table class="ui-table">
                <thead><tr><th>{{ ___('URL') }}</th><th>{{ ___('Events') }}</th><th>{{ ___('Signing secret') }}</th><th>{{ ___('Status') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th></tr></thead>
                <tbody>
                    @foreach ($webhooks as $w)
                        <tr>
                            <td data-primary><code class="ui-api__url">{{ $w->url }}</code></td>
                            <td data-label="{{ ___('Events') }}">
                                <span class="ui-api__tags">
                                    @foreach (($w->events ?? []) as $e)
                                        <x-ui.tag tone="accent" class="ui-ltr">{{ $e }}</x-ui.tag>
                                    @endforeach
                                </span>
                            </td>
                            <td data-label="{{ ___('Signing secret') }}">
                                {{-- Blurred by the lane sheet; actions.js reveal-secret swaps in
                                     the value and clears the blur inline on click. --}}
                                <code class="ui-api__reveal"
                                      title="{{ ___('Click to reveal. Use this to verify the webhook-signature header.') }}"
                                      data-ui-secret="{{ $w->secret }}"
                                      data-ui-do="reveal-secret">{{ ___('click to reveal') }}</code>
                            </td>
                            <td data-label="{{ ___('Status') }}">
                                <span class="ui-api__status">
                                    @if (! $w->active && $w->disabled_reason)
                                        <x-ui.pill tone="danger" data-status="auto-disabled">{{ ___('Auto-disabled') }}</x-ui.pill>
                                        <small>{{ $w->disabled_reason }}</small>
                                    @else
                                        <x-ui.pill :tone="$w->active ? 'success' : 'neutral'">{{ $w->active ? ___('Active') : ___('Paused') }}</x-ui.pill>
                                        @if (($w->consecutive_failures ?? 0) > 0)
                                            <small class="ui-api__warn">{{ ___('Failing:') }} <span class="ui-ltr">{{ $w->consecutive_failures }}/{{ \App\Models\WebhookEndpoint::MAX_CONSECUTIVE_FAILURES }}</span></small>
                                        @endif
                                    @endif
                                </span>
                            </td>
                            <td class="ui-catalog__row-actions">
                                <form action="{{ route('api.webhook.test', $w->id) }}" method="post">
                                    @csrf
                                    <button type="submit" class="ui-icon-btn" aria-label="{{ ___('Test') }}" title="{{ ___('Test') }}" @disabled($impBlocked)><x-ui.icon name="share" /></button>
                                </form>
                                <form action="{{ route('api.webhook.toggle', $w->id) }}" method="post">
                                    @csrf
                                    <button type="submit" class="ui-icon-btn" aria-label="{{ $w->active ? ___('Pause') : ___('Resume') }}" title="{{ $w->active ? ___('Pause') : ___('Resume') }}" @disabled($impBlocked)><x-ui.icon :name="$w->active ? 'pause' : 'refresh'" /></button>
                                </form>
                                <form action="{{ route('api.webhook.delete', $w->id) }}" method="post" data-confirm="{{ ___('Remove this webhook?') }}" data-confirm-danger>
                                    @csrf @method('DELETE')
                                    <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Remove') }}" title="{{ ___('Remove') }}" @disabled($impBlocked)><x-ui.icon name="trash" /></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>

    @if ($deliveries->isNotEmpty())
        <x-ui.card :title="___('Recent deliveries')" variant="flush">
            <table class="ui-table">
                <thead><tr><th>{{ ___('Event') }}</th><th>{{ ___('When') }}</th><th class="ui-table__num">{{ ___('Attempt') }}</th><th class="ui-table__num">{{ ___('Status') }}</th><th>{{ ___('Response') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th></tr></thead>
                <tbody>
                    @foreach ($deliveries as $d)
                        <tr>
                            <td data-primary><code>{{ $d->event }}</code></td>
                            <td data-label="{{ ___('When') }}">{{ $d->created_at?->diffForHumans() }}</td>
                            <td class="ui-table__num" data-label="{{ ___('Attempt') }}"><span class="ui-ltr">{{ $d->attempt }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Status') }}"><x-ui.pill :tone="$d->success ? 'success' : 'danger'"><span class="ui-ltr">{{ $d->http_status ?? '—' }}</span></x-ui.pill></td>
                            <td data-label="{{ ___('Response') }}"><span class="ui-api__resp">{{ Str::limit($d->response ?? '', 60) }}</span></td>
                            <td class="ui-catalog__row-actions">
                                {{-- A4: re-queue this delivery with a fresh message id. --}}
                                <form action="{{ route('api.webhook.redeliver', $d->id) }}" method="post">
                                    @csrf
                                    <x-ui.button type="submit" size="sm" variant="secondary" icon="refresh" :disabled="$impBlocked">{{ ___('Redeliver') }}</x-ui.button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
    @endif
</div>
@endsection
