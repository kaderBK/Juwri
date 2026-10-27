{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: menu_studio — the AI Menu Studio composer. A plain GET form to
     the studio app (prompt + output mode travel as query parameters, as the old
     send button did), plus the attach / sample shortcuts. No script. --}}
@if (Route::has('studio.app.shell'))
    @php $sbStudio = route('studio.app.shell'); @endphp
    <section class="mk-sec sb-block" id="menu-studio" data-site-block="menu_studio">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('Menu Studio') }} · {{ ___('Free · No signup to try') }}</span>
                <h2 class="mk-h2">{{ ___('Turn any menu into a') }} <span class="sb-accent">{{ ___('full ordering app') }}</span> {{ ___('in 60 seconds') }}</h2>
                <p class="mk-lead">{{ ___('Drop a photo, PDF, or handwritten page. The AI reads every dish and builds a live menu your guests browse, order and pay from — or a print-ready designed PDF.') }}</p>
            </div>
            <form class="mk-form sb-composer" action="{{ $sbStudio }}" method="get">
                <textarea class="ui-input sb-composer__box" name="prompt" rows="3" maxlength="2000" placeholder="{{ ___('Here’s a photo of my menu — make it an online ordering menu…') }}" aria-label="{{ ___('Describe or paste your menu') }}"></textarea>
                <div class="sb-composer__bar">
                    <ul class="mk-chips">
                        <li><a href="{{ $sbStudio }}">📎 {{ ___('Attach menu') }}</a></li>
                        <li><a href="{{ $sbStudio }}">📷 {{ ___('Photo') }}</a></li>
                        <li><a href="{{ $sbStudio }}">📄 PDF</a></li>
                    </ul>
                    <fieldset class="mk-cycle sb-composer__mode">
                        <label class="mk-cycle__btn"><input type="radio" name="output" value="ordering" checked> {{ ___('Ordering app') }}</label>
                        <label class="mk-cycle__btn"><input type="radio" name="output" value="pdf"> {{ ___('PDF menu') }}</label>
                    </fieldset>
                    <button type="submit" class="ui-btn">{{ ___('Convert') }}</button>
                </div>
            </form>
            <ul class="mk-chips mk-chips--center">
                <li><a href="{{ $sbStudio }}">🍕 {{ ___('Try the trattoria sample') }}</a></li>
                <li><a href="{{ $sbStudio }}">✍️ {{ ___('Handwritten menu') }}</a></li>
                @if (Route::has('studio'))
                    <li><a href="{{ route('studio') }}">{{ ___('Explore Menu Studio') }} →</a></li>
                @endif
            </ul>
            <p class="mk-note">✓ {{ ___('No signup · uploads auto-delete in 48 h · never used to train models') }}</p>
        </div>
    </section>
@endif
