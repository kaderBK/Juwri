{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Live stores using the platform (logos are public on their menus). --}}
@if (marquee_stores()->isNotEmpty())
    @php $mkCount = active_store_count(); @endphp
    <section class="mk-trust">
        <div class="mk-wrap">
            <h2 class="mk-trust__title">
                @if ($mkCount >= 100)
                    {{ ___('Trusted by :count+ restaurants and growing.', ['count' => number_format((int) floor($mkCount / 100) * 100)]) }}
                @else
                    {{ ___('Trusted by restaurants everywhere.') }}
                @endif
            </h2>
            <ul class="mk-trust__logos">
                @foreach (marquee_stores() as $mkStore)
                    <li>
                        <a href="{{ route('publicView', $mkStore->slug) }}" target="_blank" rel="noopener" title="{{ $mkStore->title }}">
                            <img loading="lazy" decoding="async" src="{{ media_thumb_url('storage/store/logo/', $mkStore->main_image, $mkStore->id, 88) }}" alt="{{ $mkStore->title }}" width="88" height="44">
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
@endif
