{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: showcase — before/after menu conversion: a handwritten menu
     (left) and the menu the AI designed from it (right), as two kit panels.
     The sample menu text is a fixed demo (not translated, as before). --}}
<section class="mk-sec sb-block" id="showcase" data-site-block="showcase">
    <div class="mk-wrap">
        <div class="mk-sec__head">
            <h2 class="mk-h2">{{ ___('From the page on your counter to the menu on their phone') }}</h2>
            <p class="mk-lead">{{ ___('A real conversion: one photographed, handwritten menu — read, structured, and re-designed by the AI.') }}</p>
        </div>
        <div class="sb-ba">
            <div class="mk-panel sb-ba__before" aria-label="{{ ___('Before: handwritten menu') }}" role="group">
                <div class="mk-panel__head">Nonna's Kitchen</div>
                <ul>
                    <li>Bruschetta ..... 6</li>
                    <li>Caprese salad ... 8.5</li>
                    <li><s>Minestrone — 7</s></li>
                    <li>Rigatoni al forno .. 14</li>
                    <li>Osso buco (ask!) . 22</li>
                    <li>Tiramisu ....... 7</li>
                    <li>House red / glass . 6</li>
                </ul>
            </div>
            <p class="sb-ba__mid">
                <span class="sb-ba__arrow" aria-hidden="true">→</span>
                <span>12 {{ ___('dishes') }} · 4 {{ ___('sections') }} · {{ ___('prices & notes kept') }} · 38 s</span>
            </p>
            <div class="mk-panel sb-ba__after" aria-label="{{ ___('After: designed menu') }}" role="group">
                <div class="sb-ba__name">Nonna's Kitchen<small>Cucina Casalinga · Est. 1987</small></div>
                <p class="sb-ba__sec">Antipasti</p>
                <div class="mk-panel__row"><span>Bruschetta</span><span>6.00</span></div>
                <p class="sb-ba__desc">{{ ___('grilled bread, tomato, basil, olive oil') }}</p>
                <div class="mk-panel__row"><span>Caprese Salad</span><span>8.50</span></div>
                <p class="sb-ba__sec">Primi &amp; Secondi</p>
                <div class="mk-panel__row"><span>Rigatoni al Forno</span><span>14.00</span></div>
                <div class="mk-panel__row"><span>Osso Buco</span><span>22.00</span></div>
                <p class="sb-ba__desc">{{ ___("chef's choice — ask for today's preparation") }}</p>
                <p class="sb-ba__sec">Dolci</p>
                <div class="mk-panel__row"><span>Tiramisu</span><span>7.00</span></div>
            </div>
        </div>
        @if (Route::has('studio.app.shell'))
            <div class="mk-actions"><a href="{{ route('studio.app.shell') }}" class="ui-btn">{{ ___('Convert my menu — free') }}</a></div>
        @endif
    </div>
</section>
