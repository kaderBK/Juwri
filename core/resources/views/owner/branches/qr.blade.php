{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Branch QR codes'))
@section('page_title', ___('Branch QR codes'))
@section('page_sub', $store->title)

@section('page_actions')
    <x-ui.button :href="route('branches.index', ['store_id' => $store->id])" variant="secondary" icon="back">
        {{ ___('Back to branches') }}
    </x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-bqr">
    <section class="ui-card ui-account__hero">
        <div>
            <p class="ui-overline">{{ ___('Per-branch QR') }}</p>
            <h2 class="ui-account__name">{{ ___('Scan straight to a branch') }}</h2>
            <p class="ui-account__row-sub">{{ ___('Each QR opens the menu pre-set to that branch, with its own items, prices and hours.') }}</p>
        </div>
        <dl class="ui-account__facts ui-bqr__facts">
            <div><dt>{{ ___('Branches') }}</dt><dd>{{ count($branches) }}</dd></div>
        </dl>
    </section>

    @if(count($branches))
        <div class="ui-bqr__grid">
            @foreach ($branches as $b)
                <article class="ui-card branch-qr-card ui-bqr__card">
                    <h3 class="ui-bqr__name">{{ $b->name }}</h3>
                    <div class="branch-qr ui-bqr__code">
                        <img src="{{ route('stores.qrSvg', $store->id) }}?branch_id={{ $b->id }}" alt="{{ $b->name }}" width="200" height="200">
                    </div>
                    <p class="ui-bqr__link ui-ltr">{{ $b->deep_link }}</p>
                    <div class="ui-bqr__actions">
                        <x-ui.button :href="$b->deep_link" variant="secondary" size="sm" icon="external" target="_blank" rel="noopener">{{ ___('Open') }}</x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" icon="download" data-qr-download="{{ $b->id }}">{{ ___('Download') }}</x-ui.button>
                    </div>
                </article>
            @endforeach
        </div>
    @else
        <div class="ui-card"><x-ui.empty :title="___('No branches.')" icon="qr" /></div>
    @endif
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    (function () {
        // QR images render server-side (SVG); rasterise to PNG for download.
        document.querySelectorAll('[data-qr-download]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var card = btn.closest('.branch-qr-card');
                var img = card && card.querySelector('.branch-qr img');
                if (!img) return;
                var size = 1024;
                var image = new Image();
                image.onload = function () {
                    var canvas = document.createElement('canvas');
                    canvas.width = size; canvas.height = size;
                    canvas.getContext('2d').drawImage(image, 0, 0, size, size);
                    var a = document.createElement('a');
                    a.download = 'branch-qr-' + btn.dataset.qrDownload + '.png';
                    a.href = canvas.toDataURL('image/png');
                    a.click();
                };
                image.src = img.src;
            });
        });
    })();
</script>
@endpush
