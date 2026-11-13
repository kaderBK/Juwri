{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{--
  Owner UI kit reference (route owner.ui.kit, OwnerUiKitController). Developer
  page: plain English on purpose (no ___() keys to translate). Renders every
  <x-ui.*> component in every state inside the real layout — flip dark mode in the
  top bar and switch to an RTL language to check both.
--}}

@section('title', 'Owner UI kit')
@section('page_title', 'Owner UI kit')
@section('page_sub', 'Tokens and components for the redesigned owner screens. Toggle dark mode in the top bar; switch to Arabic to check right-to-left.')

@section('content')
@php
    $swatches = [
        'Surfaces' => ['--ui-bg', '--ui-surface', '--ui-inset', '--ui-line', '--ui-control-line'],
        'Ink' => ['--ui-ink', '--ui-ink-2', '--ui-ink-3', '--ui-selected-bg', '--ui-hero-bg'],
        'Accent' => ['--ui-accent', '--ui-accent-soft', '--ui-accent-text', '--ui-on-accent'],
        'Status' => ['--ui-success', '--ui-warning', '--ui-danger', '--ui-neutral', '--ui-badge'],
    ];
    $motion = [
        ['--ui-motion-fast', '120ms', 'Press, hover, colour, exits'],
        ['--ui-motion-base', '200ms', 'Switches, toasts, card moves'],
        ['--ui-motion-moderate', '280ms', 'Sheets, dialogs, new order'],
        ['--ui-motion-slow', '400ms', 'Highlights fading out'],
        ['--ui-motion-count', '600ms', 'Number count-up, chart growth'],
    ];
@endphp
<div class="ui-kit ui-stack" style="--ui-gap: 40px">

    <section class="ui-stack" aria-labelledby="kit-colour">
        <h2 id="kit-colour" class="ui-section-title">Colour tokens</h2>
        <div class="ui-grid" style="--ui-grid-min: 220px">
            @foreach ($swatches as $group => $tokens)
                <x-ui.card :title="$group" heading="h3">
                    <div class="ui-stack" style="--ui-gap: 10px">
                        @foreach ($tokens as $token)
                            <div class="ui-cluster" style="--ui-gap: 10px">
                                <span style="inline-size: 40px; block-size: 28px; border-radius: 8px; border: 1px solid var(--ui-line); background: var({{ $token }})"></span>
                                <code style="font-family: var(--ui-font-mono); font-size: 12px; color: var(--ui-ink-2)">{{ $token }}</code>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    </section>

    <section class="ui-stack" aria-labelledby="kit-type">
        <h2 id="kit-type" class="ui-section-title">Typography</h2>
        <x-ui.card>
            <div class="ui-stack" style="--ui-gap: 14px">
                <div class="ui-display ui-num" style="font-size: var(--ui-fs-hero-lg); font-weight: 700; line-height: 1">4,862.50</div>
                <div class="ui-display" style="font-size: var(--ui-fs-title); font-weight: 700">Page title · مساء الخير</div>
                <div style="font-size: var(--ui-fs-section); font-weight: 700">Section · Needs attention</div>
                <div style="font-size: var(--ui-fs-body)">Body · Customers can order now</div>
                <div style="font-size: var(--ui-fs-label); font-weight: 600">Label · Taking orders</div>
                <div style="font-size: var(--ui-fs-caption); color: var(--ui-ink-2)">Caption · Oldest placed 4 min ago</div>
                <div class="ui-overline">Overline · Categories</div>
            </div>
        </x-ui.card>
    </section>

    <section class="ui-stack" aria-labelledby="kit-buttons">
        <h2 id="kit-buttons" class="ui-section-title">Buttons</h2>
        <x-ui.card>
            <div class="ui-stack">
                <div class="ui-cluster">
                    <x-ui.button icon="check">Accept order</x-ui.button>
                    <x-ui.button variant="dark">Mark ready</x-ui.button>
                    <x-ui.button variant="secondary" icon="print">Print</x-ui.button>
                    <x-ui.button variant="ghost">Cancel</x-ui.button>
                    <x-ui.button variant="danger">Reject</x-ui.button>
                    <x-ui.button variant="success">Hand to driver</x-ui.button>
                    <x-ui.button variant="link" href="#kit-buttons">View all</x-ui.button>
                </div>
                <div class="ui-cluster">
                    <x-ui.button size="lg">Large · 52</x-ui.button>
                    <x-ui.button>Medium · 44</x-ui.button>
                    <x-ui.button size="sm">Small · 36</x-ui.button>
                    <x-ui.button disabled>Disabled</x-ui.button>
                    <x-ui.button :busy="true">Saving…</x-ui.button>
                </div>
                <div class="ui-cluster">
                    <x-ui.icon-button label="Search" icon="search" />
                    <x-ui.icon-button label="Notifications, 3 unread" icon="bell" :dot="true" />
                    <x-ui.icon-button label="Add" icon="plus" variant="filled" />
                    <x-ui.icon-button label="Print ticket" icon="print" variant="ghost" />
                </div>
            </div>
        </x-ui.card>
    </section>

    <section class="ui-stack" aria-labelledby="kit-inputs">
        <h2 id="kit-inputs" class="ui-section-title">Inputs &amp; selection</h2>
        <div class="ui-grid">
            <x-ui.field name="kit_store_name" label="Store name" value="Crave Burger — Olaya" help="Shown to customers on your menu." />
            <x-ui.field name="kit_price" type="number" label="Price" prefix="SAR" value="0.00" error="Enter a price above 0." step="0.01" />
            <x-ui.field name="kit_category" type="select" label="Category" value="burgers" :options="['burgers' => 'Burgers', 'sides' => 'Sides', 'drinks' => 'Drinks']" />
            <x-ui.field name="kit_url" label="Store URL" value="juwri.com/crave" help="Disabled · set by your plan" disabled />
            <x-ui.field name="kit_search" type="search" label="Search" placeholder="Search 13 products" />
            <x-ui.field name="kit_note" type="textarea" label="Kitchen note" value="Please cut the burgers in half." />
        </div>
        <div class="ui-grid">
            <x-ui.card title="Switches" heading="h3">
                <div class="ui-stack" style="--ui-gap: 6px">
                    <x-ui.switch name="kit_taking" :checked="true" label="Taking orders" description="Customers can order now" />
                    <x-ui.switch name="kit_print" label="Auto-print tickets" />
                    <x-ui.switch name="kit_closed" :disabled="true" label="Closed today" description="Outside opening hours" />
                </div>
            </x-ui.card>
            <x-ui.card title="Checkboxes & radios" heading="h3">
                <div class="ui-stack" style="--ui-gap: 4px">
                    <x-ui.check name="kit_types[]" value="delivery" label="Delivery" :checked="true" />
                    <x-ui.check name="kit_types[]" value="pickup" label="Pickup" />
                    <x-ui.check type="radio" name="kit_pay" value="cash" label="Cash" :checked="true" />
                    <x-ui.check type="radio" name="kit_pay" value="card" label="Card" />
                </div>
            </x-ui.card>
            <x-ui.card title="Segmented & chips" heading="h3">
                <div class="ui-stack">
                    <x-ui.segmented name="kit_period" value="today" label="Period" :options="['today' => 'Today', 'week' => '7 days', 'month' => '30 days']" />
                    <div class="ui-chips">
                        <x-ui.chip :active="true" :count="13">All</x-ui.chip>
                        <x-ui.chip :count="5">Burgers</x-ui.chip>
                        <x-ui.chip :count="3">Sides</x-ui.chip>
                    </div>
                </div>
            </x-ui.card>
        </div>
    </section>

    <section class="ui-stack" aria-labelledby="kit-status">
        <h2 id="kit-status" class="ui-section-title">Status &amp; badges</h2>
        <x-ui.card>
            <div class="ui-stack">
                <div class="ui-cluster">
                    <x-ui.pill tone="success">Open</x-ui.pill>
                    <x-ui.pill tone="warning">Busy</x-ui.pill>
                    <x-ui.pill>Paused</x-ui.pill>
                    <x-ui.pill tone="accent">New</x-ui.pill>
                    <x-ui.pill tone="danger">Rejected</x-ui.pill>
                    <x-ui.pill tone="success" :live="true">LIVE</x-ui.pill>
                </div>
                <div class="ui-cluster">
                    <x-ui.tag tone="accent">Best seller</x-ui.tag>
                    <x-ui.tag tone="danger">Sold out</x-ui.tag>
                    <x-ui.tag tone="warning">4 left</x-ui.tag>
                    <x-ui.tag tone="success">Paid · Apple Pay</x-ui.tag>
                    <x-ui.tag>Pay at table</x-ui.tag>
                </div>
                <div class="ui-cluster">
                    <x-ui.count :value="3" />
                    <x-ui.count :value="120" />
                    <x-ui.count :value="12" tone="accent" />
                    <x-ui.count :value="0" tone="neutral" :show-zero="true" />
                </div>
            </div>
        </x-ui.card>
    </section>

    <section class="ui-stack" aria-labelledby="kit-cards">
        <h2 id="kit-cards" class="ui-section-title">Cards &amp; lists</h2>
        <div class="ui-grid" style="--ui-grid-min: 320px; align-items: start">
            <x-ui.card variant="hero">
                <x-ui.hero-figure label="Sales today" value="4,862.50" unit="SAR" delta="+12.4%" note="vs last Thursday, same time" />
            </x-ui.card>
            <div class="ui-stack">
                <div class="ui-grid" style="--ui-grid-min: 120px; --ui-gap: 10px">
                    <x-ui.stat :value="8" label="tickets in the kitchen" icon="flame" tone="warning" />
                    <x-ui.stat value="12/20" label="tables seated" icon="dine-in" tone="success" />
                    <x-ui.stat :value="3" label="drivers on the road" icon="delivery" />
                </div>
                <x-ui.list>
                    <x-ui.row href="#kit-cards" icon="orders" title="3 new orders waiting" sub="Oldest placed 4 min ago">
                        <x-slot:trail><x-ui.tag tone="accent">Review</x-ui.tag></x-slot:trail>
                    </x-ui.row>
                    <x-ui.row href="#kit-cards" icon="alert" tone="warning" title="1 sold out, 2 running low" sub="Lotus Cheesecake · Truffle Fries (4 left)" />
                    <x-ui.row href="#kit-cards" icon="star" tone="danger" title="2-star review needs a reply" sub="“Fries were cold on arrival” · Al Malqa" />
                </x-ui.list>
            </div>
        </div>
    </section>

    <section class="ui-stack" aria-labelledby="kit-table">
        <h2 id="kit-table" class="ui-section-title">Table → cards (under 768px)</h2>
        <x-ui.card variant="flush">
            <table class="ui-table">
                <thead><tr><th scope="col">Product</th><th scope="col">Category</th><th scope="col" class="ui-table__num">Price</th><th scope="col">Stock</th></tr></thead>
                <tbody>
                    <tr><td data-primary data-label="Product">Classic Smash</td><td data-label="Category">Burgers</td><td data-label="Price" class="ui-table__num">SAR 32.00</td><td data-label="Stock"><x-ui.tag tone="success">In stock</x-ui.tag></td></tr>
                    <tr><td data-primary data-label="Product">Truffle Fries</td><td data-label="Category">Sides</td><td data-label="Price" class="ui-table__num">SAR 19.00</td><td data-label="Stock"><x-ui.tag tone="warning">4 left</x-ui.tag></td></tr>
                    <tr><td data-primary data-label="Product">Lotus Cheesecake</td><td data-label="Category">Desserts</td><td data-label="Price" class="ui-table__num">SAR 21.00</td><td data-label="Stock"><x-ui.tag tone="danger">Sold out</x-ui.tag></td></tr>
                </tbody>
            </table>
        </x-ui.card>
    </section>

    <section class="ui-stack" aria-labelledby="kit-feedback">
        <h2 id="kit-feedback" class="ui-section-title">Feedback &amp; states</h2>
        <div class="ui-grid">
            <div class="ui-stack" style="--ui-gap: 10px">
                <x-ui.banner>You are editing a menu shared by 2 stores.</x-ui.banner>
                <x-ui.banner tone="warning">1 product sold out, 2 running low.</x-ui.banner>
                <x-ui.banner tone="danger">Your payment gateway key expired. Customers can’t pay by card.</x-ui.banner>
                <div class="ui-cluster">
                    <x-ui.button variant="secondary" size="sm" data-ui-toast="#1051 accepted · ready in 15 min">Show toast</x-ui.button>
                    <x-ui.button variant="secondary" size="sm" data-ui-toast="Couldn’t save. Check your connection and try again." data-ui-toast-tone="danger">Show error toast</x-ui.button>
                </div>
            </div>
            <x-ui.card>
                <x-ui.empty icon="products" title="No products yet" body="Add your first product or import your menu from a file.">
                    <x-ui.button icon="plus">Add product</x-ui.button>
                </x-ui.empty>
            </x-ui.card>
            <x-ui.card>
                <x-ui.skeleton :rows="3" />
            </x-ui.card>
        </div>
    </section>

    <section class="ui-stack" aria-labelledby="kit-overlays">
        <h2 id="kit-overlays" class="ui-section-title">Sheet (bottom sheet on phones, dialog on desktop)</h2>
        <div class="ui-cluster">
            <x-ui.button variant="dark" icon="pause" data-ui-open="kit-pause-sheet">Pause Al Malqa…</x-ui.button>
        </div>
        <x-ui.sheet id="kit-pause-sheet" title="Pause Al Malqa?" description="Customers can’t order until you resume. Orders already cooking are not affected.">
            <div class="ui-stack" style="--ui-gap: 8px">
                <x-ui.check type="radio" name="kit_pause_for" value="30" label="For 30 minutes" />
                <x-ui.check type="radio" name="kit_pause_for" value="21" label="Until 9:00 PM" :checked="true" />
                <x-ui.check type="radio" name="kit_pause_for" value="manual" label="Until I resume" />
            </div>
            <x-slot:footer>
                <x-ui.button variant="secondary" data-ui-close>Cancel</x-ui.button>
                <x-ui.button variant="dark" data-ui-close data-ui-toast="Al Malqa paused. Customers see “Back soon”.">Pause store</x-ui.button>
            </x-slot:footer>
        </x-ui.sheet>
    </section>

    <section class="ui-stack" aria-labelledby="kit-motion">
        <h2 id="kit-motion" class="ui-section-title">Motion</h2>
        <x-ui.card variant="flush">
            <table class="ui-table">
                <thead><tr><th scope="col">Token</th><th scope="col">Duration</th><th scope="col">Use</th></tr></thead>
                <tbody>
                    @foreach ($motion as [$token, $ms, $use])
                        <tr><td data-primary data-label="Token"><code style="font-family: var(--ui-font-mono)">{{ $token }}</code></td><td data-label="Duration">{{ $ms }}</td><td data-label="Use">{{ $use }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.card>
        <p style="margin: 0; font-size: var(--ui-fs-caption); color: var(--ui-ink-2)">Only transform and opacity animate. Under reduced motion, movement becomes instant or a fade and loops stop.</p>
    </section>

</div>
@endsection
