{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Merchant Email Campaign'))
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="mail" :href="route('admin.mailtemplates.index')">{{ ___('Email Templates') }}</x-ui.button>
@endsection

@section('content')
    @php
        $__filters = $criteria['filters'];
    @endphp

    <form id="merchantCampaignForm" method="post" action="{{ route('admin.merchants.campaign.send') }}"
          data-preview-url="{{ route('admin.merchants.campaign.preview') }}"
          class="ui-stack">
        @csrf

        <x-ui.card :title="___('Segment')">
            <div class="ui-grid-3">
                <div class="ui-field">
                    <label class="ui-field__label" for="mc-campaign">{{ ___('Campaign key') }}</label>
                    <input id="mc-campaign" name="campaign" class="ui-input" type="text" value="{{ $campaign }}"
                           pattern="[A-Za-z0-9_\-]+" maxlength="64" required>
                    <span class="ui-field__hint">{{ ___('Used for idempotency and the report. Re-running the same key never sends twice.') }}</span>
                </div>

                <div class="ui-field">
                    <label class="ui-field__label" for="mc-plan">{{ ___('Plan') }}</label>
                    <select id="mc-plan" name="plan" class="ui-select">
                        <option value="any" @selected(($__filters['plan'] ?? '') === 'any')>{{ ___('Any plan') }}</option>
                        <option value="free" @selected(($__filters['plan'] ?? '') === 'free')>{{ ___('Free') }}</option>
                        <option value="trial" @selected(($__filters['plan'] ?? '') === 'trial')>{{ ___('Trial') }}</option>
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}" @selected(($__filters['plan'] ?? '') === (string) $plan->id)>{{ $plan->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ui-field">
                    <label class="ui-field__label" for="mc-country">{{ ___('Country') }}</label>
                    <select id="mc-country" name="country" class="ui-select">
                        <option value="any" @selected(($__filters['country'] ?? '') === 'any')>{{ ___('Any country') }}</option>
                        @foreach ($countries as $country)
                            <option value="{{ $country }}" @selected(($__filters['country'] ?? '') === (string) $country)>{{ $country }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="ui-field">
                    <label class="ui-field__label" for="mc-trial">{{ ___('Trial state') }}</label>
                    <select id="mc-trial" name="trial" class="ui-select">
                        <option value="any" @selected(($__filters['trial'] ?? '') === 'any')>{{ ___('Any') }}</option>
                        <option value="ending" @selected(($__filters['trial'] ?? '') === 'ending')>{{ ___('Ending soon') }}</option>
                        <option value="ended" @selected(($__filters['trial'] ?? '') === 'ended')>{{ ___('Ended') }}</option>
                    </select>
                </div>

                <div class="ui-field">
                    <label class="ui-field__label" for="mc-trial-within">{{ ___('Ending within (days)') }}</label>
                    <input id="mc-trial-within" name="trial_within" class="ui-input" type="number" min="1" max="90"
                           value="{{ $__filters['trial_within'] ?? 7 }}">
                </div>

                <div class="ui-field">
                    <label class="ui-field__label" for="mc-limit">{{ ___('Limit') }}</label>
                    <input id="mc-limit" name="limit" class="ui-input" type="number" min="0" max="100000"
                           value="{{ $criteria['limit'] ?? '' }}" placeholder="{{ ___('No limit') }}">
                </div>

                <div class="ui-field">
                    <label class="ui-field__label" for="mc-has-sub">{{ ___('Only merchants with a subscription') }}</label>
                    <select id="mc-has-sub" name="has_subscription" class="ui-select">
                        <option value="" @selected(($__filters['has_subscription'] ?? '') === '')>{{ ___('Any') }}</option>
                        <option value="1" @selected(($__filters['has_subscription'] ?? '') === '1')>{{ ___('Yes') }}</option>
                    </select>
                </div>

                <div class="ui-field">
                    <label class="ui-field__label" for="mc-per-minute">{{ ___('Emails per minute') }}</label>
                    <input id="mc-per-minute" name="per_minute" class="ui-input" type="number" min="1" max="600" value="60">
                </div>

                <div class="ui-field">
                    <label class="ui-field__label" for="mc-scheduled-at">{{ ___('Schedule for') }}</label>
                    <input id="mc-scheduled-at" name="scheduled_at" class="ui-input" type="datetime-local"
                           value="{{ $criteria['scheduled_at'] ?? '' }}">
                    <span class="ui-field__hint">{{ ___('Leave empty to send as soon as you press the button.') }}</span>
                </div>
            </div>

            <div class="ui-field">
                <label class="ui-field__label" for="mc-user-ids">{{ ___('Only these merchants (ids)') }}</label>
                <input id="mc-user-ids" name="user_ids" class="ui-input" type="text"
                       value="{{ implode(',', $criteria['user_ids'] ?? []) }}"
                       placeholder="{{ ___('Leave empty to use the filters above') }}">
                <span class="ui-field__hint">{{ ___('Choosing merchants below fills this in and overrides the filters above.') }}</span>
            </div>
        </x-ui.card>

        <x-ui.card :title="___('Select merchants')">
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" size="sm" class="js-clear-merchants">{{ ___('Clear') }}</x-ui.button>
            </x-slot:actions>

            <div class="ui-stack">
                <input type="search" class="ui-input js-merchant-search"
                       placeholder="{{ ___('Search by name, username or email') }}"
                       autocomplete="off" value="{{ $criteria['merchant_search'] ?? '' }}">
                <div class="js-merchant-results ui-card" style="max-height: 320px; overflow-y: auto; padding: 0;">
                    <div class="ui-muted js-merchant-empty" style="padding: 12px 16px;">{{ ___('Search to find merchants.') }}</div>
                </div>
                <span class="ui-field__hint">{{ ___('Selected') }}: <span class="js-merchant-count">0</span></span>
            </div>
        </x-ui.card>

        <x-ui.card :title="___('The message')">
            <div class="ui-stack">
                <x-ui.field name="subject" id="mc-subject" :label="___('Subject')" maxlength="190" :value="$subject" />

                <div class="ui-field">
                    <label class="ui-field__label" for="campaignBody">{{ ___('Message') }}</label>
                    <textarea name="body" id="campaignBody" rows="10" data-ui-rich>{{ $body }}</textarea>
                    <span class="ui-field__hint">{{ ___('The message body is HTML. These shortcodes are replaced for each merchant:') }}</span>
                    <div class="ui-chips">
                        @foreach (['{SITE_TITLE}', '{SITE_URL}', '{OWNER_NAME}', '{COUNTRY}', '{PLAN}', '{UNSUBSCRIBE_URL}'] as $shortcode)
                            <button type="button" class="ui-chip ui-mono js-insert-shortcode" data-ui-copy-text="{{ $shortcode }}" data-code="{{ $shortcode }}" title="{{ ___('Copy') }}">{{ $shortcode }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card :title="___('Recipients')">
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" size="sm" icon="eye" class="js-preview-campaign">{{ ___('Preview') }}</x-ui.button>
            </x-slot:actions>

            <div class="ui-stats" id="previewCounts">
                <x-ui.stat :value="$preview['to_send']" :label="___('Will be sent')" icon="send" data-preview-key="to_send" />
                <x-ui.stat :value="$preview['already']" :label="___('Already sent')" icon="check" data-preview-key="already" />
                <x-ui.stat :value="$preview['opted_out']" :label="___('Opted out')" icon="bell-off" data-preview-key="opted_out" />
                <x-ui.stat :value="$preview['total']" :label="___('Matched')" icon="users" data-preview-key="total" />
            </div>
            <p class="ui-muted" style="margin-top: 12px;">
                {{ ___('Every recipient is logged, and a merchant who unsubscribed is never contacted again.') }}
            </p>
        </x-ui.card>

        <div class="ui-row" style="justify-content: flex-end; flex-wrap: wrap; gap: 8px;">
            @if ($pending > 0)
                <x-ui.button type="button" variant="danger" class="js-stop-campaign">
                    {{ ___('Stop this campaign') }} ({{ $pending }})
                </x-ui.button>
            @endif
            <x-ui.button type="button" variant="secondary" icon="send" class="js-test-campaign">{{ ___('Send a test to me') }}</x-ui.button>
            <x-ui.button type="button" variant="secondary" class="js-preview-campaign">{{ ___('Preview') }}</x-ui.button>
            <x-ui.button type="submit" icon="send">{{ ___('Queue the campaign') }}</x-ui.button>
        </div>
    </form>

    @if ($scheduled)
        <x-ui.card :title="___('Scheduled campaigns')">
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                    <tr>
                        <th>{{ ___('Campaign') }}</th>
                        <th>{{ ___('When') }}</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($scheduled as $one)
                        <tr>
                            <td>{{ $one['campaign'] }}</td>
                            <td>{{ date('Y-m-d H:i', $one['at']) }}</td>
                            <td style="text-align: end;">
                                <x-ui.button variant="danger" size="sm" :href="route('admin.merchants.campaign', ['campaign' => $one['campaign']])">{{ ___('Stop this campaign') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    @if ($reports)
        <x-ui.card :title="___('Recent campaigns')">
            <div class="ui-table-wrap">
                <table class="ui-table">
                    <thead>
                    <tr>
                        <th>{{ ___('Campaign') }}</th>
                        <th>{{ ___('Total') }}</th>
                        <th>{{ ___('Sent') }}</th>
                        <th>{{ ___('Opted out') }}</th>
                        <th>{{ ___('Failed') }}</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($reports as $report)
                        <tr>
                            <td>{{ $report->campaign }}</td>
                            <td>{{ $report->total }}</td>
                            <td>{{ $report->sent ?? 0 }}</td>
                            <td>{{ $report->opted_out ?? 0 }}</td>
                            <td>{{ $report->failed ?? 0 }}</td>
                            <td style="text-align: end;">
                                <x-ui.button variant="secondary" size="sm" :href="route('admin.merchants.campaign.report', ['campaign' => $report->campaign])">{{ ___('Report') }}</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
@endsection

@push('scripts_at_bottom')
    <script nonce="{{ csp_nonce() }}">
        (function () {
            const form = document.getElementById('merchantCampaignForm');
            const counts = document.getElementById('previewCounts');
            const buttons = document.querySelectorAll('.js-preview-campaign');

            function syncEditor() {
                if (window.tinymce && typeof tinymce.triggerSave === 'function') {
                    tinymce.triggerSave();
                }
                const body = document.getElementById('campaignBody');
                if (body && body.dispatchEvent) {
                    body.dispatchEvent(new Event('change'));
                }
            }

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    syncEditor();

                    const data = new FormData(form);
                    data.append('campaign', form.querySelector('[name=campaign]').value);

                    fetch(form.dataset.previewUrl || '{{ route('admin.merchants.campaign.preview') }}', {
                        method: 'POST',
                        headers: {'X-CSRF-TOKEN': form.querySelector('[name=_token]').value},
                        body: data
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (json) {
                            if (!json.success) { return; }
                            const p = json.preview;
                            const vals = [p.to_send, p.already, p.opted_out, p.total];
                            counts.querySelectorAll('.ui-stat__val').forEach(function (el, i) {
                                if (vals[i] !== undefined) { el.textContent = String(vals[i]); }
                            });
                        });
                });
            });

            form.addEventListener('submit', syncEditor);

            const userIdsField = form.querySelector('[name=user_ids]');
            const results = document.querySelector('.js-merchant-results');
            const search = document.querySelector('.js-merchant-search');
            const counter = document.querySelector('.js-merchant-count');
            const clearButton = document.querySelector('.js-clear-merchants');
            const selected = new Set(
                (userIdsField.value || '').split(',').map(function (v) { return v.trim(); }).filter(Boolean)
            );

            function writeSelection() {
                userIdsField.value = Array.from(selected).join(',');
                if (counter) {
                    counter.textContent = String(selected.size);
                }
            }

            function renderResults(merchants) {
                if (! results) { return; }
                results.innerHTML = '';

                if (! merchants.length) {
                    results.innerHTML = '<div class="ui-muted" style="padding: 12px 16px;">' +
                        '{{ ___('No merchants found.') }}' + '</div>';

                    return;
                }

                merchants.forEach(function (merchant) {
                    const row = document.createElement('label');
                    row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid var(--ui-border);cursor:pointer;';

                    const box = document.createElement('input');
                    box.type = 'checkbox';
                    box.className = 'js-merchant-check';
                    box.value = String(merchant.id);
                    box.checked = selected.has(String(merchant.id));
                    box.addEventListener('change', function () {
                        if (box.checked) {
                            selected.add(String(merchant.id));
                        } else {
                            selected.delete(String(merchant.id));
                        }
                        writeSelection();
                    });

                    const text = document.createElement('span');
                    const bits = [merchant.name || merchant.email];
                    if (merchant.store) { bits.push(merchant.store); }
                    if (merchant.country) { bits.push(merchant.country); }
                    bits.push(merchant.email);
                    text.textContent = bits.join(' · ');

                    row.appendChild(box);
                    row.appendChild(text);
                    results.appendChild(row);
                });
            }

            let searchTimer = null;
            if (search) {
                search.addEventListener('input', function () {
                    clearTimeout(searchTimer);
                    searchTimer = setTimeout(function () {
                        const url = '{{ route('admin.merchants.campaign.merchants') }}?q=' +
                            encodeURIComponent(search.value || '') + '&limit=50';

                        fetch(url, {headers: {'Accept': 'application/json'}})
                            .then(function (r) { return r.json(); })
                            .then(function (json) {
                                renderResults(json.merchants || []);
                            });
                    }, 350);
                });
            }

            if (clearButton) {
                clearButton.addEventListener('click', function () {
                    selected.clear();
                    writeSelection();
                    results.querySelectorAll('.js-merchant-check').forEach(function (box) {
                        box.checked = false;
                    });
                });
            }

            writeSelection();

            form.addEventListener('submit', function (event) {
                const first = counts ? counts.querySelector('.ui-stat__val') : null;
                const message = '{{ ___('This will email :count merchants. Continue?') }}';

                if (! window.confirm(message.replace(':count', first ? first.textContent.trim() : '0'))) {
                    event.preventDefault();
                }
            });

            document.querySelectorAll('.js-test-campaign').forEach(function (button) {
                button.addEventListener('click', function () {
                    syncEditor();

                    const data = new FormData(form);
                    data.append('subject', form.querySelector('[name=subject]').value);
                    data.append('body', form.querySelector('[name=body]').value);

                    fetch('{{ route('admin.merchants.campaign.test') }}', {
                        method: 'POST',
                        headers: {'X-CSRF-TOKEN': form.querySelector('[name=_token]').value},
                        body: data
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (json) {
                            window.alert(json.message || (json.success ? 'sent' : 'failed'));
                        });
                });
            });

            document.querySelectorAll('.js-stop-campaign').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (! window.confirm('{{ ___('Stop this campaign? The queued emails will not be sent.') }}')) {
                        return;
                    }

                    const data = new FormData(form);
                    data.append('campaign', form.querySelector('[name=campaign]').value);

                    fetch('{{ route('admin.merchants.campaign.cancel') }}', {
                        method: 'POST',
                        headers: {'X-CSRF-TOKEN': form.querySelector('[name=_token]').value},
                        body: data
                    })
                        .then(function (r) { return r.json(); })
                        .then(function (json) {
                            window.alert(json.message || (json.success ? 'stopped' : 'failed'));
                            if (json.success) {
                                window.location.reload();
                            }
                        });
                });
            });
        })();
    </script>
@endpush
