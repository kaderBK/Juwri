{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Side column of the user pages (edit, password): profile, plan and limits,
  plan / backup actions, tenant health and page-template permissions.
  $plans comes from the admin.users.userdetails view composer.
--}}
@php
    $plan = $user->plan();
    /* F7-6: limits go through the resolver (effective_limit → Entitlements), so add-on
       packs, overrides and subscription snapshots show, with the one sentinel check. */
    $adminLimit = function (string $type) use ($user) {
        $value = effective_limit($type, $user);

        return \App\Services\Entitlements::isUnlimitedSentinel($value) ? ___('Unlimited') : number_format($value);
    };
    /* WI-H16: purchased (plan + add-ons, pre-clamp) vs granted (post-clamp) where paid > granted. */
    $capacityTruncations = [];
    foreach (['restaurant', 'category', 'menu', 'scan'] as $__capKey) {
        $__purchased = \App\Services\Entitlements::limit($user, $__capKey); // null = unlimited
        $__granted = effective_limit($__capKey, $user);
        if ($__purchased !== null && ! \App\Services\Entitlements::isUnlimitedSentinel($__granted) && $__purchased > $__granted) {
            $capacityTruncations[$__capKey] = ['purchased' => $__purchased, 'granted' => $__granted];
        }
    }
    // Same meaning as the edit form and the list: 1 = active, 0 = banned.
    $__statusTag = (string) $user->status === '1' ? ['success', ___('Active')] : ['danger', ___('Banned')];
    $__feature = function (bool $on, string $label) {
        return ['on' => $on, 'label' => $label];
    };
    $__features = [
        $__feature((bool) ($plan->settings->allow_ordering ?? false), ___('Allow stores to accept orders')),
        $__feature((bool) ($plan->settings->table_qr ?? false), ___('Allow restaurants to create table specific QR codes.')),
        $__feature((bool) ($plan->settings->hide_branding ?? false), ___('Hide Branding')),
        $__feature(! ($plan->settings->advertisements ?? false), ___('No Advertisements')),
    ];
    foreach ((array) ($plan->settings->custom_features ?? []) as $__key => $__value) {
        $__opt = plan_option($__key);
        if ($__opt) {
            $__features[] = $__feature((bool) $__value, ! empty($__opt->translations->{get_lang()}->title) ? $__opt->translations->{get_lang()}->title : $__opt->title);
        }
    }
    $__liveSub = $user->liveSubscription();
    $__pausedSub = app(\Juwri\Core\Contracts\Billing\Subscriptions::class)->latestPaused($user);
    $__planActions = Route::has('admin.users.plan'); // P1-13 13g: Billing (ee)
    $__health = \Juwri\Core\Services\TenantHealthService::for($user);
    $__ltOverride = \Juwri\Modules\Content\Services\LockedTemplates\Permissions::hasOverride($user->id);
    $__ltCaps = \Juwri\Modules\Content\Services\LockedTemplates\Permissions::forUser($user->id);
    $__ltAllowed = \Juwri\Modules\Content\Services\LockedTemplates\Permissions::allowedTemplates($user->id);
    $__ltTemplates = \Juwri\Modules\Content\Models\LockedTemplate::query()->orderBy('name')->get(['id', 'name', 'status']);
@endphp
<x-ui.card>
    <div class="ui-stack ui-center">
        <img id="filePreview" class="ui-avatar-lg" src="{{ asset('storage/profile/'.$user->image) }}" width="110" height="110" alt="">
        <h2 class="ui-card__title">{{ $user->name }}</h2>
    </div>
    <dl class="ui-dl">
        <div><dt>{{ ___('Username') }}</dt><dd>{{ $user->username }}</dd></div>
        <div><dt>{{ ___('Email') }}</dt><dd>{{ $user->email }}</dd></div>
        <div><dt>{{ ___('Status') }}</dt><dd><x-ui.tag :tone="$__statusTag[0]">{{ $__statusTag[1] }}</x-ui.tag></dd></div>
        <div><dt>{{ ___('Email Verify') }}</dt><dd><x-ui.tag :tone="$user->email_verified_at ? 'success' : 'warning'">{{ $user->email_verified_at ? ___('Verified') : ___('Unverified') }}</x-ui.tag></dd></div>
        <div><dt>{{ ___('Last Active') }}</dt><dd>{{ date_formating($user->lastactive) }}</dd></div>
        <div><dt>{{ ___('Joined at') }}</dt><dd>{{ date_formating($user->created_at) }}</dd></div>
    </dl>
</x-ui.card>

<x-ui.card :title="$plan?->name ?? ___('No plan')">
    @if ($user->upgrade && $user->upgrade->interval)
        <x-slot:actions><x-ui.tag tone="accent">{{ plan_interval_text($user->upgrade->interval) }}</x-ui.tag></x-slot:actions>
    @endif
    <ul class="ui-plan-list">
        <li>{!! ___('Plan store limit stores', ['plan_restaurant_limit' => '<strong>'.e($adminLimit('restaurant')).'</strong>']) !!}</li>
        <li>{!! ___(':plan_category_limit Menu Categories', ['plan_category_limit' => '<strong>'.e($adminLimit('category')).'</strong>']) !!}</li>
        <li>{!! ___(':plan_menu_limit Menu Items Per Category', ['plan_menu_limit' => '<strong>'.e($adminLimit('menu')).'</strong>']) !!}</li>
        <li>{!! ___(':plan_scan_limit Scans Per Month', ['plan_scan_limit' => '<strong>'.e($adminLimit('scan')).'</strong>']) !!}</li>
        @foreach ($__features as $__f)
            <li>
                <span @class(['ui-text-success' => $__f['on'], 'ui-text-danger' => ! $__f['on']])><x-ui.icon :name="$__f['on'] ? 'check' : 'x'" size="16" /></span>
                <span class="ui-visually-hidden">{{ $__f['on'] ? ___('Yes') : ___('No') }}:</span>
                {{ $__f['label'] }}
            </li>
        @endforeach
    </ul>
    @if (! empty($capacityTruncations))
        {{-- WI-H16: paid-but-ungranted capacity, so support can answer the ticket. --}}
        <x-ui.banner tone="warning">
            <strong>{{ ___('Purchased capacity exceeds the account cap') }}</strong>
            <ul>
                @foreach ($capacityTruncations as $__capKey => $__cap)
                    <li>{!! ___(':type — paid :purchased, granted :granted', [
                        'type' => e(___(ucfirst($__capKey))),
                        'purchased' => '<strong>'.number_format($__cap['purchased']).'</strong>',
                        'granted' => '<strong>'.number_format($__cap['granted']).'</strong>',
                    ]) !!}</li>
                @endforeach
            </ul>
        </x-ui.banner>
    @endif
    <div class="ui-stack ui-card__foot">
        @if ($__planActions)
            <x-ui.button icon="gift" block data-ui-open="change-plan-panel">{{ ___('Change Plan') }}</x-ui.button>
        @endif
        <x-ui.button variant="secondary" icon="download" block :href="route('admin.users.fullExport', $user->id)">{{ ___('Export Full Data') }}</x-ui.button>
        <x-ui.button variant="secondary" icon="box" block :href="route('admin.users.hungerinoExport', $user->id)">{{ ___('Hungerino Export') }}</x-ui.button>
        <x-ui.button variant="secondary" icon="upload" block data-ui-open="import-full-data-panel">{{ ___('Import Full Data') }}</x-ui.button>
    </div>
</x-ui.card>

{{-- HPMS E12(c): tenant health — assembled from existing platform data. --}}
<x-ui.card :title="___('Tenant health')">
    <dl class="ui-dl" data-tenant-health>
        <div><dt>{{ ___('Plan') }}</dt><dd>{{ $__health['plan'] }} <x-ui.tag :tone="$__health['plan_active'] ? 'success' : 'neutral'">{{ $__health['plan_active'] ? ___('Active') : ___('Inactive') }}</x-ui.tag></dd></div>
        <div><dt>{{ ___('Stores') }}</dt><dd>{{ $__health['stores']['used'] }} / {{ $__health['stores']['limit'] >= 999 ? '∞' : $__health['stores']['limit'] }}</dd></div>
        <div><dt>{{ ___('Webhook failures') }}</dt><dd @class(['ui-text-danger' => $__health['webhook_failures'] > 0])>{{ $__health['webhook_failures'] }}</dd></div>
        <div><dt>{{ ___('Failed jobs') }}</dt><dd @class(['ui-text-warning' => $__health['failed_jobs'] > 0])>{{ $__health['failed_jobs'] }}</dd></div>
        <div><dt>{{ ___('Last backup') }}</dt><dd>{{ $__health['last_backup_at'] ? date_formating($__health['last_backup_at']) : '—' }}</dd></div>
    </dl>
</x-ui.card>

{{-- Locked page templates: what THIS user may change when editing a designed template. --}}
<x-ui.card :title="___('Page template permissions')" id="locked-template-permissions">
    <form action="{{ route('admin.locked-templates.permissions.user') }}" method="post" class="ui-stack">
        @csrf
        <input type="hidden" name="user_id" value="{{ $user->id }}">
        <p class="ui-small ui-text-2">{{ ___('What this user may change when editing a designed page template on their reseller site.') }}
            <a href="{{ route('admin.locked-templates.index') }}">{{ ___('Manage templates & defaults') }}</a></p>
        <div class="ui-cluster">
            <x-ui.check type="radio" name="mode" value="default" :checked="! $__ltOverride" :label="___('Use the defaults')" />
            <x-ui.check type="radio" name="mode" value="custom" :checked="$__ltOverride" :label="___('Custom for this user')" />
        </div>
        <fieldset class="ui-stack ui-fieldset">
            <legend class="ui-small ui-text-2">{{ ___('Custom permissions (used when "Custom" is selected)') }}</legend>
            @foreach (\Juwri\Modules\Content\Services\LockedTemplates\Permissions::CAPS as $__cap => $__label)
                <x-ui.switch :name="'caps['.$__cap.']'" :checked="(bool) ($__ltCaps[$__cap] ?? false)" :label="___($__label)" />
            @endforeach
            <x-ui.check name="all_templates" value="1" :checked="$__ltAllowed === null" :label="___('May use every published template')" />
            @if ($__ltTemplates->isNotEmpty())
                <div class="ui-field">
                    <label class="ui-field__label" for="lt-templates">{{ ___('Otherwise, only these templates:') }}</label>
                    <select id="lt-templates" name="templates[]" multiple data-ui-multi>
                        @foreach ($__ltTemplates as $__tpl)
                            <option value="{{ $__tpl->id }}" @selected(is_array($__ltAllowed) && in_array($__tpl->id, $__ltAllowed, true))>{{ $__tpl->name }}@if ($__tpl->status !== 'published') ({{ ___('draft') }})@endif</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </fieldset>
        <div><x-ui.button type="submit" size="sm" icon="check">{{ ___('Save template permissions') }}</x-ui.button></div>
    </form>
</x-ui.card>

<x-ui.sheet id="import-full-data-panel" :title="___('Import Full Data')">
    <form id="import-user-backup-form" action="{{ route('admin.users.fullImport', $user->id) }}" method="POST" enctype="multipart/form-data" class="ui-stack">
        @csrf
        <x-ui.banner tone="warning">{{ ___('This will replace the current user subscription and restaurant data with the uploaded backup.') }}</x-ui.banner>
        <x-ui.field type="file" name="backup_file" :label="___('Backup File')" accept=".zip" required
                    :help="___('Maximum').': '.\Juwri\Core\Support\BackupZipValidator::maxEntries().' '.___('files').', '.(int) round(\Juwri\Core\Support\BackupZipValidator::maxUncompressedBytes() / 1048576).' MB '.___('uncompressed').'.'" />
        {{-- WI-C3: default checked — preview what a restore deletes and restores per table before it runs. --}}
        <x-ui.check name="dry_run" value="1" :checked="true" :label="___('Preview what will be deleted and restored (recommended)')" />
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" data-ui-close>{{ ___('Cancel') }}</x-ui.button>
        <x-ui.button type="submit" form="import-user-backup-form" icon="upload">{{ ___('Import') }}</x-ui.button>
    </x-slot:footer>
</x-ui.sheet>

@if ($__planActions)
<x-ui.sheet id="change-plan-panel" :title="___('Change Plan')">
    <div class="ui-stack">
        {{-- P4 (decision E): admin-only seasonal pause / resume. --}}
        @if ($__liveSub && $__liveSub->status === 'active' && $__liveSub->plan_id)
            <form method="post" action="{{ route('admin.users.subscription.pause', $user->id) }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" size="sm" icon="pause">{{ ___('Pause subscription (seasonal)') }}</x-ui.button>
            </form>
        @elseif ($__pausedSub)
            <form method="post" action="{{ route('admin.users.subscription.resume', $user->id) }}" class="ui-stack">
                @csrf
                <x-ui.button type="submit" variant="success" size="sm" icon="refresh">{{ ___('Resume subscription') }}</x-ui.button>
                <small class="ui-text-2">{{ ___('Paused — the pause duration extends the expiry on resume.') }}</small>
            </form>
        @endif
        <form id="change-plan-form" action="{{ route('admin.users.plan', $user->id) }}" method="POST" class="ui-stack">
            @csrf
            <x-ui.field type="select" name="plan" id="change-plan-plan" :label="___('Plan')" :value="(string) ($plan?->id ?? '')"
                        :options="['free' => ___('Free'), 'trial' => ___('Trial')] + collect($plans ?? [])->pluck('name', 'id')->all()" />
            <x-ui.field type="select" name="package_trial_done" :label="___('Trial Done')" :value="user_options($user->id, 'package_trial_done') ? '1' : '0'"
                        :options="['1' => ___('Yes'), '0' => ___('No')]" />
            <div data-plan-expiry>
                <x-ui.field type="date" name="plan_expiration_date" id="id_exdate" :label="___('Expiration Date')"
                            :value="$user->upgrade ? date_formating($user->upgrade->upgrade_expires, 'Y-m-d') : date('Y-m-d')" />
            </div>
        </form>
    </div>
    <x-slot:footer>
        <x-ui.button variant="secondary" data-ui-close>{{ ___('Cancel') }}</x-ui.button>
        <x-ui.button type="submit" form="change-plan-form" icon="check">{{ ___('Save') }}</x-ui.button>
    </x-slot:footer>
</x-ui.sheet>
@endif
@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    // The expiry date only applies to paid and trial plans.
    (function () {
        var plan = document.getElementById('change-plan-plan');
        var expiry = document.querySelector('[data-plan-expiry]');
        if (!plan || !expiry) { return; }
        var sync = function () { expiry.hidden = plan.value === 'free'; };
        plan.addEventListener('change', sync);
        sync();
    })();
</script>
@endpush
