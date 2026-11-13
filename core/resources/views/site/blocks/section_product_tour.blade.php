{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: section_product_tour — five product surfaces as kit panels
     (a wrapped grid instead of the old swipe rail), plus the live demo link. --}}
@php
    $sbTour = [
        ['store', ___('Storefront'), ___('What diners see'), ___('40 themes wired to the real ordering engine, customized without code.'), [
            [___('Theme: Crave'), 'accent', ___('Published')],
            [___('Arabic menu'), 'success', ___('Auto-translated')],
            [___('Ramadan banner'), null, ___('Scheduled for iftar')],
            [___('Sold-out items'), null, ___('Hide at zero stock')],
            [___('Reservations + waitlist'), 'success', ___('On')],
        ]],
        ['kitchen', ___('Operations'), ___('The floor, the pass, the till'), ___('Full POS with shifts and drawers, kitchen screens per station, live floor map.'), [
            [___('Grill station'), 'warning', ___('4 open tickets')],
            [___('Fryer station'), 'success', ___('All clear')],
            [___('Table 7 · Zone A'), null, ___('Seated 24 min, bill requested')],
            [___('Shift drawer'), null, ___('Opened 16:00 by Youssef')],
            [___('Branch: Olaya'), 'accent', ___('Active')],
        ]],
        ['users', ___('Marketing'), ___('Regulars, on autopilot'), ___('Every order builds your list. Loyalty and reviews work it for you.'), [
            [___('Sleeping regulars'), null, ___('112 diners, 30+ days quiet')],
            [___('WhatsApp blast'), 'success', ___('38% opened')],
            [___('Bio page'), null, ___('1,204 link clicks this week')],
            [___('Google reviews'), null, ___('4.7 average this month')],
            [___('Points issued'), null, ___('18,240 this week')],
            [___('Iftar campaign'), 'warning', ___('Scheduled')],
        ]],
        ['wallet', ___('Back office'), ___('Not a shoebox of receipts'), ___('Accounting, people and support live where the sales happen.'), [
            [___('Food cost'), null, ___('28.4% of revenue this month')],
            [___('Payroll run'), 'success', ___('Approved')],
            [___('PO #218 · Al Watania'), 'warning', ___('Awaiting delivery')],
            [___('Expense: gas refill'), null, ___('Receipt attached')],
            [___('Nightly backup'), 'success', ___('Completed')],
        ]],
        ['settings', ___('Developers'), ___('Open where it matters'), ___('REST API with scopes, signed webhooks with retries, 5 MCP servers, official Zapier, Make and n8n apps.'), [
            [___('REST API'), null, ___('Per-key ability scopes')],
            [___('Webhooks'), 'success', ___('Signed + retried')],
            [___('Zapier · Make · n8n'), 'success', ___('Official apps')],
            [___('MCP servers'), null, ___('Admin, catalog, customer, dev, ordering')],
            [___('Marketplace'), null, ___('Publish themes and apps')],
            [___('Developer docs'), 'accent', ___('Public')],
        ]],
    ];
@endphp
<section class="mk-sec mk-sec--tint sb-block" data-site-block="section_product_tour">
    <div class="mk-wrap">
        <div class="mk-sec__head">
            <span class="mk-eyebrow">{{ ___('Product tour') }}</span>
            <h2 class="mk-h2">{{ ___('Five surfaces. Swipe through.') }}</h2>
        </div>
        <div class="mk-grid mk-grid--3">
            @foreach ($sbTour as [$sbIcon, $sbTag, $sbTitle, $sbSub, $sbRows])
                <article class="mk-panel sb-tour">
                    <div class="mk-panel__head"><x-ui.icon :name="$sbIcon" /> {{ $sbTag }}</div>
                    <h3 class="mk-h3">{{ $sbTitle }}</h3>
                    <p class="mk-small">{{ $sbSub }}</p>
                    @foreach ($sbRows as [$sbLabel, $sbTone, $sbValue])
                        <div class="mk-panel__row">
                            <span>{{ $sbLabel }}</span>
                            @if ($sbTone)
                                <x-ui.pill :tone="$sbTone">{{ $sbValue }}</x-ui.pill>
                            @else
                                <span class="mk-panel__note">{{ $sbValue }}</span>
                            @endif
                        </div>
                    @endforeach
                </article>
            @endforeach
        </div>
        <div class="mk-actions">
            <a href="{{ route('publicView', 'crave-demo') }}" target="_blank" rel="noopener" class="ui-btn ui-btn--secondary">{{ ___('See it live in a demo store') }}</a>
        </div>
    </div>
</section>
