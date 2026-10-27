{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  P1-23: the lines under a plan / add-on "pay" button where JUWRI LTD's legal pages are published —
  site.checkout.accept (Subscription Terms link, auto-renew, cancel in Settings) and site.checkout.vat.
  Paying records the owner's acceptance of the Terms in force (CheckoutController, source "checkout").
--}}
@if (\Juwri\Core\Legal\LegalDocuments::publishedHere())
    <div class="ui-checkout__legal" data-checkout-legal>
        <p>{{ \Juwri\Core\Legal\LegalText::inline('site.checkout.accept') }}</p>
        <p>{{ __('site.checkout.vat') }}</p>
    </div>
@endif
