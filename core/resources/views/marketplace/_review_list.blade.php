{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- stores P3-3: published reviews + rating distribution. Vars:
       $avg (float), $count (int), $distribution (array 5..1 => n),
       $reviews (Collection). Escaped output only. --}}
<style nonce="{{ csp_nonce() }}">
    .reviews .rev-summary { display:grid; grid-template-columns:auto 1fr; gap:28px; align-items:center; margin-bottom:18px; }
    @media (max-width:560px){ .reviews .rev-summary { grid-template-columns:1fr; gap:12px; } }
    .rev-avg { text-align:center; }
    .rev-avg .big { font-size:44px; font-weight:800; line-height:1; font-family:'Syne','Instrument Sans',sans-serif; }
    .rev-avg .big span { font-size:20px; color:var(--muted); }
    .rev-avg .stars { color:var(--star); letter-spacing:2px; font-size:16px; }
    .rev-dist { display:flex; flex-direction:column; gap:6px; }
    .rev-bar { display:flex; align-items:center; gap:10px; font-size:13px; color:var(--muted); }
    .rev-bar .lab { width:34px; text-align:end; }
    .rev-bar .track { flex:1; height:8px; background:var(--bg); border-radius:999px; overflow:hidden; }
    .rev-bar .fill { height:100%; background:var(--star); }
    .rev-bar .n { width:28px; }
    .rev-list { list-style:none; margin:0; padding:0; }
    .rev-list li { border-top:1px solid var(--line); padding-block:14px; }
    .rev-list li:first-child { border-top:0; }
    .rev-list .stars { color:var(--star); letter-spacing:1px; }
    .rev-list .who { color:var(--muted); font-size:13px; margin-top:4px; }
    .rev-list .rbody { margin:6px 0 0; white-space:pre-line; }
    .rev-list .seller-reply { margin-top:10px; padding:10px 14px; border-inline-start:3px solid var(--accent); background:var(--bg); border-radius:0 10px 10px 0; }
</style>
<section class="block reviews" id="reviews">
    <h2>{{ ___('Reviews') }}</h2>

    @if($count === 0)
        <p class="muted" style="margin:0;">{{ ___('No reviews yet.') }}</p>
    @else
        <div class="rev-summary">
            <div class="rev-avg">
                <div class="big">{{ number_format($avg, 1) }}<span>/5</span></div>
                <div class="stars">{{ str_repeat('★', (int) round($avg)) }}{{ str_repeat('☆', 5 - (int) round($avg)) }}</div>
                <div class="muted" style="font-size:13px;">{{ ___(':count reviews', ['count' => $count]) }}</div>
            </div>
            <div class="rev-dist">
                @for($s = 5; $s >= 1; $s--)
                    <div class="rev-bar">
                        <span class="lab">{{ $s }}★</span>
                        <div class="track"><div class="fill" style="width:{{ $count ? round(($distribution[$s] / $count) * 100) : 0 }}%"></div></div>
                        <span class="n">{{ $distribution[$s] }}</span>
                    </div>
                @endfor
            </div>
        </div>

        <ul class="rev-list">
            @foreach($reviews as $r)
                <li>
                    <div class="stars">{{ str_repeat('★', (int) $r->rating) }}{{ str_repeat('☆', 5 - (int) $r->rating) }}</div>
                    @if($r->title)<b dir="auto">{{ $r->title }}</b>@endif
                    @if($r->body)<p class="rbody" dir="auto">{{ $r->body }}</p>@endif
                    <div class="who"><bdi>{{ $r->user?->name ?? ___('User') }}</bdi> · {{ $r->created_at?->diffForHumans() }}</div>
                    @if($r->reply)
                        <div class="seller-reply">
                            <div class="who" style="font-weight:600;color:var(--accent-ink);margin:0 0 2px;">{{ ___('Developer response') }}</div>
                            <p class="rbody" style="margin:0;" dir="auto">{{ $r->reply }}</p>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
