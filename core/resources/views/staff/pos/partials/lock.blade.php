{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3: lock overlay — covers the register; PIN swaps staff, password re-auths owner.
     Layout + colours live in tokenised classes (.lock-overlay, in the extracted
     stylesheet) so x-show's display toggle reverts to flex and both themes read. --}}
<div class="lock-overlay" x-show="locked" x-cloak role="dialog" aria-modal="true" aria-labelledby="lock-title" aria-describedby="lock-hint">
    <div class="lock-card">
        <div style="margin-bottom:6px">@include('staff.pos.partials.icon', ['name' => 'lock', 'size' => 40])</div>
        <h2 id="lock-title" class="lock-title">{{ ___('Register locked') }}</h2>
        <p id="lock-hint" class="lock-hint">
            @if($isOwner){{ ___('Enter your password — or a staff PIN to hand the register to a cashier.') }}@else{{ ___('Enter your staff PIN to unlock or switch staff.') }}@endif
        </p>
        <p class="lock-err" x-show="lockErr" x-text="lockErr" role="alert"></p>
        @if($isOwner)
            {{-- Owner terminal: password re-auths the owner; a staff PIN swaps the
                 actor to that cashier (unlock() already sends whichever is filled). --}}
            <input type="password" x-model="pwInput" @keydown.enter="unlock()" aria-label="{{ ___('Password') }}"
                   placeholder="{{ ___('Password') }}">
            <input class="pin" type="password" inputmode="numeric" x-model="pinInput" @keydown.enter="unlock()"
                   aria-label="{{ ___('or staff PIN') }}" placeholder="{{ ___('or staff PIN') }}" maxlength="6" style="margin-top:8px">
        @else
            <input class="pin" type="password" inputmode="numeric" x-model="pinInput" @keydown.enter="unlock()"
                   aria-label="{{ ___('PIN') }}" placeholder="{{ ___('PIN') }}" maxlength="6" x-init="$el.focus()">
        @endif
        <button class="lock-unlock" @click="unlock()">{{ ___('Unlock') }}</button>
    </div>
</div>
