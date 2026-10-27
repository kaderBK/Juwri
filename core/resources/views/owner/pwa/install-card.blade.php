{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Owner dashboard PWA install / order-alerts card (rev/mobile-app/PWA-SPEC.md §3.4).
     pwa.js shows exactly one [data-state] (or hides the card). Hidden by
     default so it never flashes; never rendered inside the native app.
     $persistent = true on the settings page (ignores "Not now", shows "on").
     Built on the owner UI kit (.ui-card / .ui-btn, tokens only); layout rules
     live in assets/owner-ui/pwa.css. --}}
@if(! \Juwri\Core\Support\OwnerApp::inApp() && Route::has('owner.pwa.manifest'))
@php $persistent = $persistent ?? false; @endphp
<section class="ui-card opwa-card" data-owner-pwa-card @if($persistent) data-owner-pwa-persistent @endif hidden aria-live="polite">
    <span class="opwa-card__icon" aria-hidden="true"><x-ui.icon name="bell" size="22" /></span>

    <div class="opwa-card__body">
        <div data-state="install" hidden>
            <h2 class="opwa-card__title">{{ ___('Install the dashboard on your phone') }}</h2>
            <p class="opwa-card__text">{{ ___('Open it from your home screen like an app and get an alert the moment an order arrives.') }}</p>
        </div>
        <div data-state="ios" hidden>
            <h2 class="opwa-card__title">{{ ___('Add the dashboard to your Home Screen') }}</h2>
            <p class="opwa-card__text">{{ ___('In Safari, tap the Share button, then “Add to Home Screen”. Open it from there to turn on order alerts.') }}</p>
        </div>
        <div data-state="alerts" hidden>
            <h2 class="opwa-card__title">{{ ___('Turn on order alerts') }}</h2>
            <p class="opwa-card__text">{{ ___('Get a notification on this device for every new order, even when the dashboard is closed.') }}</p>
        </div>
        <div data-state="on" hidden>
            <h2 class="opwa-card__title">{{ ___('Order alerts are on for this device') }}</h2>
            <p class="opwa-card__text">{{ ___('Choose which alerts you get in Mobile app & push settings.') }}</p>
        </div>
        <div data-state="blocked" hidden>
            <h2 class="opwa-card__title">{{ ___('Notifications are blocked') }}</h2>
            <p class="opwa-card__text">{{ ___('Allow notifications for this site in your browser settings, then try again.') }}</p>
        </div>
        <div data-state="error" hidden>
            <h2 class="opwa-card__title">{{ ___('Could not turn on alerts') }}</h2>
            <p class="opwa-card__text">{{ ___('Something went wrong. Please try again.') }}</p>
        </div>
    </div>

    <div class="opwa-card__actions">
        <x-ui.button data-state="install" data-owner-pwa-action="install" hidden>{{ ___('Install app') }}</x-ui.button>
        <x-ui.button data-state="alerts" data-owner-pwa-action="alerts" hidden>{{ ___('Turn on alerts') }}</x-ui.button>
        <x-ui.button data-state="error" data-owner-pwa-action="alerts" hidden>{{ ___('Try again') }}</x-ui.button>
        @if($persistent)
            <x-ui.button variant="secondary" data-state="on" data-owner-pwa-action="alerts-off" hidden>{{ ___('Turn off on this device') }}</x-ui.button>
        @else
            <x-ui.button variant="ghost" data-owner-pwa-action="dismiss">{{ ___('Not now') }}</x-ui.button>
        @endif
    </div>
</section>
@endif
