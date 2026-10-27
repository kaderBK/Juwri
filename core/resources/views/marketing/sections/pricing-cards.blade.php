{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Plan cards from the live plan data. Needs $plans (public, active plans);
    reads $free_plan / $trial_plan (config tiers) and $gateHome (home page: the
    compare table obeys the "compare on home" setting).

    Card lines, in order of preference: the plan's curated marketing lines
    (settings.marketing_features, or marketing_features_en outside Arabic), the
    Plan::marketing_features accessor, else the restaurant limit plus up to four
    features the plan includes. At most six lines.
    Prices carry data-{monthly,yearly,lifetime}-price for the cycle switch; a paid
    plan without a given cycle shows "—", only a free tier reads "Free".
--}}
@php
    $pcSign = cur_isolate($settings->currency_sign ?? '$');
    $pcSignFirst = currency_pos_effective() === 1;
    $pcMoney = function ($amount) use ($pcSign, $pcSignFirst) {
        if (($amount ?? 0) <= 0) {
            return ___('Free');
        }
        $n = number_format($amount, 0);

        return money_ltr($pcSignFirst ? $pcSign.$n : $n.$pcSign);
    };
    $pcCycle = fn ($amount, $monthly) => (($amount ?? 0) <= 0 && ($monthly ?? 0) > 0) ? '—' : $pcMoney($amount);

    $pcCards = collect($plans)->values();
    if ((string) config('settings.pricing_free_trial_cards', '1') !== '0') {
        if (! empty($free_plan) && (string) ($free_plan->status ?? '1') !== '0') {
            $pcCards->prepend($free_plan);
        }
        if (! empty($trial_plan) && (string) ($trial_plan->status ?? '1') !== '0') {
            $pcCards->push($trial_plan);
        }
    }
    $pcRec = $pcCards->search(fn ($p) => ($p->recommended ?? 'no') === 'yes');

    $pcSetting = function ($plan, string $key) {
        $s = $plan->settings ?? null;

        return is_object($s) ? ($s->{$key} ?? null) : (is_array($s) ? ($s[$key] ?? null) : null);
    };
    $pcLines = function ($plan) use ($pcSetting) {
        $curated = app()->getLocale() === 'ar'
            ? $pcSetting($plan, 'marketing_features')
            : ($pcSetting($plan, 'marketing_features_en') ?: $pcSetting($plan, 'marketing_features'));
        if (is_array($curated) || is_object($curated)) {
            $lines = array_values(array_filter(array_map(
                fn ($l) => is_scalar($l) ? trim((string) $l) : '',
                (array) $curated
            ), fn ($l) => $l !== ''));
            if ($lines !== []) {
                return array_slice($lines, 0, 6);
            }
        }
        if (method_exists($plan, 'getMarketingFeaturesAttribute')) {
            $accessor = $plan->marketing_features;
            if (is_array($accessor) && $accessor !== []) {
                return array_slice(array_values($accessor), 0, 6);
            }
        }

        try {
            $rows = app(\Juwri\Core\Contracts\Billing\PlanCatalog::class)->entitlementRows($plan->id);
        } catch (\Throwable $e) {
            $rows = collect();
        }
        $lines = [];
        $limit = $rows->has('restaurant_limit') ? $rows['restaurant_limit']->int_value : $pcSetting($plan, 'restaurant_limit');
        if ($limit !== null && $limit !== '') {
            $lines[] = (\App\Services\Entitlements::isUnlimitedRawLimit($limit) ? ___('Unlimited') : number_format((int) $limit)).' '.___('Stores');
        }
        $flags = 0;
        foreach (\App\Services\FeatureRegistry::all() as $feature) {
            if ($flags >= 4) {
                break;
            }
            $row = $rows[$feature['key']] ?? null;
            if ($row ? (bool) $row->bool_value : (bool) ($feature['default'] ?? false)) {
                $lines[] = $feature['label'];
                $flags++;
            }
        }

        return $lines;
    };
    $pcText = function ($plan, string $field) {
        $local = $plan->translations->{get_lang()}->{$field} ?? null;

        return ! empty($local) ? $local : ($plan->{$field} ?? '');
    };
@endphp

<div class="mk-price-grid">
    @foreach ($pcCards as $pcI => $plan)
        @php
            $pcIsRec = $pcI === $pcRec;
            $pcM = $plan->monthly_price ?? 0;
            $pcA = $plan->annual_price ?? 0;
            $pcSave = ($pcM > 0 && $pcA > 0) ? (int) round((1 - $pcA / ($pcM * 12)) * 100) : 0;
            $pcHref = (auth()->check() && Route::has('checkout.index') ? route('checkout.index') : route('register')).'?plan='.$plan->id;
        @endphp
        <article class="pricing-card-b{{ $pcIsRec ? ' pricing-card-b--rec' : '' }}">
            @if ($pcIsRec)
                <span class="pricing-card-b__pop">{{ ___('Most popular') }}</span>
            @endif
            <h3 class="pricing-card-b__name">{{ $pcText($plan, 'name') }}</h3>
            <p class="pricing-card-b__tagline">{{ \Illuminate\Support\Str::limit(strip_tags((string) $pcText($plan, 'description')), 60) }}</p>
            <div class="pricing-card-b__price">
                <p class="pricing-card-b__amount" data-monthly-price="{{ $pcMoney($pcM) }}" data-yearly-price="{{ $pcCycle($pcA, $pcM) }}" data-lifetime-price="{{ $pcCycle($plan->lifetime_price ?? 0, $pcM) }}">{{ $pcMoney($pcM) }}</p>
                @if ($pcM > 0)
                    <p class="pricing-card-b__period" data-monthly-period="{{ ___('/mo') }}" data-yearly-period="{{ ___('/yr') }}" data-lifetime-period="{{ ___('one-time') }}">{{ ___('/mo') }}</p>
                @endif
            </div>
            @php $pcSettings = (array) ($plan->settings ?? []); @endphp
            @if ((string) ($pcSettings['per_branch'] ?? '') === '1')
                {{-- Pricing follow-ups: Chain is priced per branch (minimum branches billed). --}}
                <p class="pricing-card-b__per-branch">{{ ___('Per branch, minimum :n branches', ['n' => max(1, (int) ($pcSettings['min_branches'] ?? 1))]) }}</p>
            @endif
            @if ($pcSave > 0)
                <span class="pricing-card-b__save">{{ ___('Save :n% with yearly billing', ['n' => $pcSave]) }}</span>
            @endif
            <ul class="pricing-card-b__feats">
                @foreach ($pcLines($plan) as $pcLine)
                    <li>{{ $pcLine }}</li>
                @endforeach
            </ul>
            <a href="{{ $pcHref }}" class="ui-btn{{ $pcIsRec ? '' : ' ui-btn--secondary' }} pricing-card-b__cta">{{ ___('Get Started') }}</a>
        </article>
    @endforeach
</div>

<p class="pricing-card-b__compare"><a href="{{ route('pricing').'#compare' }}">{{ ___('Compare all features') }}</a></p>

@includeIf('billing::marketing.sections.pricing-compare', ['plans' => $plans, 'gate_home' => $gateHome ?? false])
