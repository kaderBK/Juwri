{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P0-4: left nav rail (UI-KIT §2). Home = register, Tables/Orders open panels
     (T3/T4 fill them), Settings = print settings. Bottom: shift chip + lock.
     Active item = primary-soft pill. Logical props → RTL mirrors for free.
     Icons are inline line-SVGs (stroke=currentColor, shared icon partial) so they
     inherit the item's text/accent colour and stay crisp + monochrome L/D. --}}
<nav class="pos-rail">
    <div class="pos-rail-nav">
        <button type="button" class="pos-rail-item" :class="{on: activePanel === 'home'}" @click="activePanel = 'home'">
            <span class="ico">@include('staff.pos.partials.icon', ['name' => 'home'])</span>{{ ___('Home') }}
        </button>
        <button type="button" class="pos-rail-item" :class="{on: activePanel === 'tables'}" @click="goPanel('tables')">
            <span class="ico">@include('staff.pos.partials.icon', ['name' => 'grid'])</span>{{ ___('Tables') }}
        </button>
        {{-- PARITY-RUN T3: the approvals count rides on the Orders rail item so a
             cashier sees a parked dine-in order without opening the panel. The
             module boots itself here (x-init) — pos-core.js is not a T3 zone. --}}
        <button type="button" class="pos-rail-item" :class="{on: activePanel === 'orders'}" @click="goPanel('orders')"
                x-init="typeof initApprovals === 'function' && initApprovals()">
            <span class="ico">@include('staff.pos.partials.icon', ['name' => 'orders'])</span>{{ ___('Orders') }}
            <span class="pos-tab-badge" x-show="(approvals || []).length" x-cloak x-text="(approvals || []).length"></span>
        </button>
        @if($isOwner || ($staff && $staff->hasPermission('pos_shift_close')))
            <button type="button" class="pos-rail-item" :class="{on: activePanel === 'manage'}" @click="goPanel('manage')">
                <span class="ico">@include('staff.pos.partials.icon', ['name' => 'chart'])</span>{{ ___('Manage') }}
            </button>
            <a class="pos-rail-item" href="{{ route('pos.settings') }}?store={{ $store->id }}">
                <span class="ico">@include('staff.pos.partials.icon', ['name' => 'settings'])</span>{{ ___('Settings') }}
            </a>
            {{-- OP-10: the manager's offline exceptions screen (same gate as Manage/Settings). --}}
            <a class="pos-rail-item" href="{{ route('pos.offline.exceptions.screen') }}?store={{ $store->id }}">
                <span class="ico">@include('staff.pos.partials.icon', ['name' => 'alert'])</span>{{ ___('Offline exceptions') }}
            </a>
        @endif
    </div>

    <div class="pos-rail-spacer"></div>

    @if($canDashboard ?? false)
        <a class="pos-rail-item" href="{{ route('dashboard') }}">
            <span class="ico">@include('staff.pos.partials.icon', ['name' => 'back'])</span>{{ ___('Dashboard') }}
        </a>
    @endif
    <div class="pos-rail-shift">{{ $staff->name ?? ($isOwner ? ___('Owner') : ___('Staff')) }}</div>
    <button type="button" class="pos-rail-item" @click="lock()">
        <span class="ico">@include('staff.pos.partials.icon', ['name' => 'lock'])</span>{{ ___('Lock') }}
    </button>
</nav>
