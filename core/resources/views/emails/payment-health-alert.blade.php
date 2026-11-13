{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-mail.frame :title="___('Payment alert')" :heading="___('Payment alert')">
    <p style="margin:0 0 12px;">{{ ___('Hi :name,', ['name' => $ownerName]) }}</p>
    <p style="margin:0 0 16px;">{{ ___('We noticed a problem with payments in :store:', ['store' => $storeTitle]) }}</p>
    <table role="presentation" class="jm-soft" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFF7ED;border:1px solid #FDBA74;border-radius:10px;margin:0 0 16px;">
        <tr><td style="padding:14px 18px;">
            @foreach ($lines as [$line, $params])
                <p style="margin:0 0 6px;">{{ ___($line, $params) }}</p>
            @endforeach
        </td></tr>
    </table>

    <x-slot:footer>
        {{ ___('You get this e-mail when payment monitoring finds a problem in your store.') }}
    </x-slot:footer>
</x-mail.frame>
