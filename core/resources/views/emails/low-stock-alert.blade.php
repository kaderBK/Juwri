{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-mail.frame :title="___('Low stock alert')" :heading="___('Low stock alert')">
    <p style="margin:0 0 12px;">{{ ___('Hi :name,', ['name' => $ownerName]) }}</p>
    <p style="margin:0 0 16px;">
        {{ ___('These items in :store are at or below their low-stock level:', ['store' => $storeTitle]) }}
    </p>
    <table role="presentation" class="jm-soft" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFF7ED;border:1px solid #FDBA74;border-radius:10px;margin:0 0 16px;">
        <tr><td style="padding:14px 18px;">
            @foreach ($names as $name)
                <p style="margin:0 0 6px;">{{ $name }}</p>
            @endforeach
            @if ($count > count($names))
                <p style="margin:0;">{{ ___('and :count more', ['count' => $count - count($names)]) }}</p>
            @endif
        </td></tr>
    </table>
    @if ($url)
        <p style="margin:0 0 16px;"><a href="{{ $url }}">{{ ___('Inventory reports') }}</a></p>
    @endif

    <x-slot:footer>
        {{ ___('You get this e-mail when an item newly falls to or below its low-stock level.') }}
    </x-slot:footer>
</x-mail.frame>
