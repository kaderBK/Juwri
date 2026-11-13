{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.shell.notices /> — account notices above every owner page: free-trial
  countdown, failed subscription payment, plan usage (hook `owner.shell.plan-notices`),
  and announcements from the owner's reseller. Inside the native app nothing here links to a purchase (Apple 3.1.3);
  the renew POST is refused there as well (BlockInAppPurchases::URIS).
--}}
@php
    $__inApp = \Juwri\Core\Support\OwnerApp::inApp();
    $__user = auth()->user();
    $__trialDays = null;
    $__pastDue = null;
    $__graceDays = null;
    $__suspended = null;
    if ($__user) {
        $__live = $__user->liveSubscription();
        if ($__live && $__live->status === 'trialing' && $__live->ends_at) {
            $__trialDays = max(0, (int) ceil(now()->diffInSeconds($__live->ends_at, false) / 86400));
        } elseif (! $__live) {
            $__last = app(\Juwri\Core\Contracts\Billing\PlanCatalog::class)->lastSubscription($__user);
            if ($__last && $__last->interval_ === 'trial' && $__last->status === 'expired') {
                $__trialDays = 0;
            } elseif ($__last && $__last->status === 'expired' && data_get($__last->meta, 'dunning.past_due_since')) {
                // P4-08: suspended for an overdue payment (moved to the Free plan). Before, nothing told the owner why
                // the plan's features had stopped — the past-due banner simply disappeared.
                $__suspended = $__last;
            }
        }
        if ($__live && $__live->status === 'past_due') {
            $__pastDue = $__live;
            if ($__live->grace_until) {
                $__graceDays = max(0, (int) ceil(now()->diffInSeconds($__live->grace_until, false) / 86400));
            }
        }
    }
    $__announcements = $__user && $__user->reseller_id
        ? app(\Juwri\Core\Contracts\Tenancy\ResellerContext::class)->announcementsFor($__user)
        : collect();
@endphp

{{-- P1-23: changed Terms / DPA / AUP to accept (30 days' notice, never a lock-out). --}}
<x-ui.shell.legal-notice />

{{-- Pricing build: plan usage notices from the billing edition (e.g. the Free plan's monthly orders); empty without it. --}}
@if ($__user)
    {{ \Juwri\Core\Support\Hooks::first('owner.shell.plan-notices', $__user) }}
@endif

@if ($__trialDays !== null)
    <x-ui.banner :tone="$__trialDays <= 2 ? 'danger' : 'warning'" class="ui-notice" data-trial-notice="{{ $__trialDays }}">
        <span>{{ $__trialDays === 0 ? ___('Your free trial has expired.') : ___(':days day(s) left in your free trial.', ['days' => $__trialDays]) }}</span>
        @if (Route::has('subscription') && ! $__inApp)
            <a class="ui-btn ui-btn--sm" href="{{ route('subscription') }}" data-purchase-cta>{{ ___('Upgrade Now') }}</a>
        @endif
    </x-ui.banner>
@endif

@if ($__pastDue)
    <x-ui.banner tone="danger" class="ui-notice" data-plan-past-due>
        @if ($__inApp)
            <span data-owner-app-plan-notice>{{ ___('Your plan needs attention.') }}</span>
        @else
            <span>
                {{ ___('Your last subscription payment failed.') }}
                @if ($__graceDays !== null)
                    {{ ___(':days day(s) left before your plan is suspended.', ['days' => $__graceDays]) }}
                @endif
            </span>
            @if (Route::has('subscription.renewNow'))
                <form method="POST" action="{{ route('subscription.renewNow') }}" data-purchase-cta>
                    @csrf
                    <button type="submit" class="ui-btn ui-btn--sm">{{ ___('Renew now') }}</button>
                </form>
            @elseif (Route::has('subscription'))
                <a class="ui-btn ui-btn--sm" href="{{ route('subscription') }}" data-purchase-cta>{{ ___('Update payment') }}</a>
            @endif
        @endif
    </x-ui.banner>
@endif

@if ($__suspended)
    <x-ui.banner tone="danger" class="ui-notice" data-plan-suspended>
        @if ($__inApp)
            <span data-owner-app-plan-notice>{{ ___('Your plan needs attention.') }}</span>
        @else
            <span>{{ ___('Your plan was suspended because a payment was overdue. Your stores and data are kept; renew to bring the plan back.') }}</span>
            @if (Route::has('subscription'))
                <a class="ui-btn ui-btn--sm" href="{{ route('subscription') }}" data-purchase-cta>{{ ___('Renew plan') }}</a>
            @endif
        @endif
    </x-ui.banner>
@endif

@if ($__announcements->isNotEmpty())
    <div class="ui-notice-stack" data-rsl-announcements>
        @foreach ($__announcements as $__a)
            <x-ui.banner class="ui-notice" data-rsl-ann="{{ $__a->id }}">
                <span class="ui-notice__text">
                    <strong>{{ $__a->title }}</strong>
                    @if (filled($__a->body))<span>{!! nl2br(e($__a->body)) !!}</span>@endif
                </span>
                <form method="POST" action="{{ route('reseller.announcement.dismiss', $__a->id) }}" data-rsl-ann-dismiss>
                    @csrf
                    <button type="submit" class="ui-icon-btn ui-icon-btn--ghost" aria-label="{{ ___('Dismiss announcement') }}"><x-ui.icon name="x" size="18" /></button>
                </form>
            </x-ui.banner>
        @endforeach
    </div>
    <script nonce="{{ csp_nonce() }}">
        (function () {
            if (window.__uiAnnDismiss) { return; }
            window.__uiAnnDismiss = true;
            // Dismiss in place; fall back to a normal POST when fetch fails.
            document.addEventListener('submit', function (e) {
                var form = e.target;
                if (!form || !form.hasAttribute || !form.hasAttribute('data-rsl-ann-dismiss') || !window.fetch) { return; }
                e.preventDefault();
                var box = form.closest('[data-rsl-ann]');
                fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { if (!r.ok) { throw new Error(String(r.status)); } if (box) { box.remove(); } })
                    .catch(function () { form.submit(); });
            });
        })();
    </script>
@endif
