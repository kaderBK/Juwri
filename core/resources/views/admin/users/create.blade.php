{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Add User')" :action="route('admin.users.store')" files>
    <x-ui.image-field name="avatar" :label="___('Image')" :src="asset('storage/profile/default_user.png')" :size="90" :hint="___('Allowed JPG, JPEG or PNG.')" />
    <x-ui.field name="name" :label="___('Full Name').' *'" required />
    <x-ui.field name="username" :label="___('Username').' *'" required />
    <x-ui.field type="email" name="email" :label="___('Email Address').' *'" required />
    <x-ui.field type="select" name="country" :label="___('Country').' *'" required
        :options="['' => ___('Choose')] + countries()->pluck('name', 'id')->all()" />
    <x-ui.field name="password" :label="___('Password').' *'" autocomplete="off" required />
</x-ui.panel>
