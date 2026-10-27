{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Account / Password switch on the user pages. $current: 'account' | 'password'. --}}
<nav class="ui-cluster" aria-label="{{ ___('Edit User') }}">
    <x-ui.button :variant="$current === 'account' ? 'primary' : 'secondary'" size="sm" icon="users" :href="route('admin.users.edit', $user->id)"
                 :aria-current="$current === 'account' ? 'page' : null">{{ ___('Account details') }}</x-ui.button>
    <x-ui.button :variant="$current === 'password' ? 'primary' : 'secondary'" size="sm" icon="lock" :href="route('admin.users.password', $user->id)"
                 :aria-current="$current === 'password' ? 'page' : null">{{ ___('Password') }}</x-ui.button>
</nav>
