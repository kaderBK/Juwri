{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- bio-t15 C1 · Editor form fields — extracted verbatim from builder.blade.php's
     <form data-editor-form> body (was inline between the hidden `type`/`branch_id`
     inputs and the "Scheduling & visibility" section). Included inside the parent
     form, so it shares $enabledLocales/$defaultLocale/$menuGroups and the form's
     own submit/csrf context. Every group is a `data-group data-types="…"` div —
     the builder script shows/hides them per the selected `type`, so ADDING a new
     type here needs no JS change beyond the type-specific populate/serialize
     helpers (see the parent view's script block). --}}

{{-- label (all types) --}}
<div data-group>
    <label class="ui-label" for="blk-label">{{ ___('Label') }}</label>
    <input type="text" class="ui-input" id="blk-label" name="label" data-field="label" maxlength="191">
</div>

{{-- url (link, reservation, image → optional link; bio-t15 A4/A6/A7/A10/A11:
     reviews/giftcard/loyalty/event/tipjar reuse this same generic URL field
     when their `mode` is 'url' — see each type's own group below) --}}
<div data-group data-types="link reservation image reviews giftcard loyalty event tipjar">
    <label class="ui-label" for="blk-url">{{ ___('URL') }}</label>
    <input type="url" class="ui-input" id="blk-url" name="url" data-field="url" maxlength="2000" placeholder="https://">
</div>

{{-- item_id (menu_item, featured_dish) — menu-item picker grouped by
     category (C3). Replaces the raw id input; stores config[item_id]. --}}
<div data-group data-types="menu_item featured_dish">
    <label class="ui-label" for="blk-item-id">{{ ___('Menu item') }}</label>
    <select class="ui-input" id="blk-item-id" name="config[item_id]" data-field="config.item_id">
        <option value="">{{ ___('— Choose an item —') }}</option>
        @foreach(($menuGroups ?? []) as $group)
            <optgroup label="{{ $group['label'] }}">
                @foreach($group['items'] as $item)
                    <option value="{{ $item['id'] }}">{{ $item['name'] }}</option>
                @endforeach
            </optgroup>
        @endforeach
    </select>
    @if(empty($menuGroups))
        <small class="ui-text-2">{{ ___('Add items to your menu to link one here.') }}</small>
    @endif
</div>
{{-- price_label (menu_item, featured_dish) --}}
<div data-group data-types="menu_item featured_dish">
    <label class="ui-label" for="blk-price-label">{{ ___('Price label') }}</label>
    <input type="text" class="ui-input" id="blk-price-label" name="config[price_label]" data-field="config.price_label" maxlength="40">
</div>
{{-- title / subtitle / schedule_label (featured_dish) --}}
<div data-group data-types="featured_dish">
    <label class="ui-label" for="blk-fd-title">{{ ___('Title') }}</label>
    <input type="text" class="ui-input" id="blk-fd-title" name="config[title]" data-field="config.title" maxlength="120">
</div>
<div data-group data-types="featured_dish">
    <label class="ui-label" for="blk-fd-subtitle">{{ ___('Subtitle') }}</label>
    <input type="text" class="ui-input" id="blk-fd-subtitle" name="config[subtitle]" data-field="config.subtitle" maxlength="160">
</div>
<div data-group data-types="featured_dish">
    <label class="ui-label" for="blk-fd-schedule-label">{{ ___('Schedule label') }}</label>
    <input type="text" class="ui-input" id="blk-fd-schedule-label" name="config[schedule_label]" data-field="config.schedule_label" maxlength="60">
</div>

{{-- announcement text --}}
<div class="ui-form-grid__full" data-group data-types="announcement">
    <label class="ui-label" for="blk-announcement-text">{{ ___('Announcement text') }}</label>
    <textarea class="ui-input" id="blk-announcement-text" name="config[text]" data-field="config.text" rows="2" maxlength="280"></textarea>
</div>

{{-- map address --}}
<div class="ui-form-grid__full" data-group data-types="map">
    <label class="ui-label" for="blk-address">{{ ___('Address override') }}</label>
    <input type="text" class="ui-input" id="blk-address" name="config[address]" data-field="config.address" maxlength="191"
           placeholder="{{ ___('Leave blank to use the store / branch address') }}">
</div>

{{-- whatsapp phone --}}
<div data-group data-types="whatsapp">
    <label class="ui-label" for="blk-whatsapp">{{ ___('WhatsApp number') }}</label>
    <input type="text" class="ui-input" id="blk-whatsapp" name="config[phone]" data-field="config.phone" maxlength="32"
           placeholder="{{ ___('Leave blank to use the store number') }}">
</div>

{{-- video media --}}
<div class="ui-form-grid__full" data-group data-types="video">
    <label class="ui-label" for="blk-video-url">{{ ___('Video file URL') }}</label>
    <input type="text" class="ui-input" id="blk-video-url" name="config[media]" data-field="config.media" maxlength="2000">
    <small class="ui-text-2">{{ ___('Self-hosted video only (MP4). External embeds are not supported.') }}</small>
</div>

{{-- capture consent --}}
<div class="ui-form-grid__full" data-group data-types="capture">
    <label class="ui-label" for="blk-consent">{{ ___('Consent line') }}</label>
    <input type="text" class="ui-input" id="blk-consent" name="config[consent_text]" data-field="config.consent_text" maxlength="191"
           placeholder="{{ ___('e.g. I agree to receive offers from this restaurant.') }}">
</div>
{{-- bio-t15 C7 · SPEC §9 deferred stub — SMS opt-in needs an SMS gateway +
     consent/DPA the platform doesn't have yet. The capture block (and A10
     event's inline capture, which reuses this same block) stays email-only;
     the toggle is disabled with an owner-setup hint, never a working control. --}}
<div class="ui-form-grid__full bio-ap-deferred" data-group data-types="capture">
    <div class="ui-check">
        <input type="checkbox" disabled>
        <label class="ui-text-2">{{ ___('Also collect a phone number (SMS opt-in)') }}</label>
    </div>
    <small class="ui-text-2"><x-ui.shell.nav-icon glyph="lock" />{{ ___('Coming soon — contact support.') }}</small>
</div>

{{-- text block: heading + body --}}
<div class="ui-form-grid__full" data-group data-types="text">
    <label class="ui-label" for="blk-text-title">{{ ___('Heading') }}</label>
    <input type="text" class="ui-input" id="blk-text-title" name="config[title]" data-field="config.title" maxlength="120"
           placeholder="{{ ___('Optional heading') }}">
</div>
<div class="ui-form-grid__full" data-group data-types="text">
    <label class="ui-label" for="blk-text-body">{{ ___('Body text') }}</label>
    <textarea class="ui-input" id="blk-text-body" name="config[text]" data-field="config.text" rows="3" maxlength="600"></textarea>
</div>

{{-- image block: image URL (label is used as the caption; URL group above adds an optional link) --}}
<div class="ui-form-grid__full" data-group data-types="image">
    <label class="ui-label" for="blk-image-url">{{ ___('Image URL') }}</label>
    <input type="text" class="ui-input" id="blk-image-url" name="config[image]" data-field="config.image" maxlength="2000"
           placeholder="https://">
    <small class="ui-text-2">{{ ___('Paste an image link, or upload one in the Media library and paste its URL. The block label becomes the caption.') }}</small>
</div>

{{-- divider block: label is optional (blank = a plain line) --}}
<div class="ui-form-grid__full" data-group data-types="divider">
    <small class="ui-text-2">{{ ___('Leave the label blank for a plain separator line, or add a short label to title a section.') }}</small>
</div>

{{-- pairs (delivery_apps, socials; bio-t15 A5/A9/A8: delivery/faq/gallery reuse
     the SAME "Name | URL"-style textarea idiom — the builder script swaps the
     label/placeholder/help text per type via data-pairs-label/data-pairs-help) --}}
<div class="ui-form-grid__full" data-group data-types="delivery_apps socials delivery faq gallery">
    <label class="ui-label" for="blk-pairs" data-pairs-label>{{ ___('Links') }}</label>
    <textarea class="ui-input" id="blk-pairs" name="config_pairs" data-field="config_pairs" rows="4"
              placeholder="{{ ___('One per line — Name | https://link') }}"></textarea>
    <small class="ui-text-2" data-pairs-help>{{ ___('One per line, format: Name | https://link') }}</small>
</div>

{{-- ─────────────────── bio-t15 Tier 1/2 new block config fields ─────────────────── --}}

{{-- A1 hours — source/timezone/note/status pill + a per-day hours textbox.
     Each day's box holds a compact free-text range list ("09:00-17:00, 18:00-22:00")
     that the builder script serialises into config[week][day][i][0/1] hidden
     inputs on submit — the render contract's nested array shape (SPEC §2 A1). --}}
<div data-group data-types="hours">
    <label class="ui-label" for="blk-hours-source">{{ ___('Hours source') }}</label>
    <select class="ui-input" id="blk-hours-source" name="config[source]" data-field="config.source">
        <option value="manual">{{ ___('Set hours manually') }}</option>
        @if($hoursAutoAvailable ?? false)
            <option value="auto">{{ ___('Use my store hours') }}</option>
        @endif
    </select>
</div>
<div data-group data-types="hours">
    <label class="ui-label" for="blk-hours-tz">{{ ___('Timezone') }}</label>
    <input type="text" class="ui-input" id="blk-hours-tz" name="config[tz]" data-field="config.tz" maxlength="64"
           placeholder="{{ ___('Leave blank to use the store timezone') }}">
</div>
<div data-group data-types="hours">
    <label class="ui-label" for="blk-hours-note">{{ ___('Note') }}</label>
    <input type="text" class="ui-input" id="blk-hours-note" name="config[note]" data-field="config.note" maxlength="120"
           placeholder="{{ ___('e.g. Kitchen closes 30 min earlier on Fridays') }}">
</div>
<div class="ui-form-grid__full" data-group data-types="hours">
    <div class="ui-check">
        <input type="hidden" name="config[show_status]" value="0">
        <input type="checkbox" role="switch" id="hours-show-status"
               name="config[show_status]" value="1" data-field="config.show_status">
        <label for="hours-show-status">{{ ___('Show an “Open now / Closed” pill') }}</label>
    </div>
</div>
<div class="ui-form-grid__full" data-group data-types="hours">
    <div class="ui-label">{{ ___('Weekly hours') }}</div>
    <div class="ui-form-grid" data-hours-week>
        @foreach(['mon' => ___('Monday'), 'tue' => ___('Tuesday'), 'wed' => ___('Wednesday'), 'thu' => ___('Thursday'), 'fri' => ___('Friday'), 'sat' => ___('Saturday'), 'sun' => ___('Sunday')] as $dayKey => $dayLabel)
            <div>
                <label class="ui-label ui-label--sm" for="blk-hours-day-{{ $dayKey }}">{{ $dayLabel }}</label>
                <input type="text" class="ui-input ui-input--sm" id="blk-hours-day-{{ $dayKey }}" data-field="config.week_text.{{ $dayKey }}"
                       data-hours-day="{{ $dayKey }}" placeholder="{{ ___('09:00-17:00, 18:00-22:00 (blank = closed)') }}">
            </div>
        @endforeach
    </div>
    <small class="ui-text-2">{{ ___('One or more time ranges per day, comma-separated. Leave a day blank to mark it closed.') }}</small>
</div>

{{-- A2 call — phone / WhatsApp / SMS with a per-channel show toggle. Empty
     channel already renders no button (SPEC §2 A2); the toggles let an owner
     hide a filled-in channel without clearing it. --}}
<div data-group data-types="call">
    <label class="ui-label" for="blk-call-phone">{{ ___('Phone number') }}</label>
    <input type="text" class="ui-input" id="blk-call-phone" name="config[phone]" data-field="config.phone" maxlength="32">
    <div class="ui-check">
        <input type="hidden" name="config[show][call]" value="0">
        <input type="checkbox" role="switch" id="call-show-call"
               name="config[show][call]" value="1" data-field="config.show.call" data-default-checked>
        <label class="ui-small" for="call-show-call">{{ ___('Show call button') }}</label>
    </div>
</div>
<div data-group data-types="call">
    <label class="ui-label" for="blk-call-whatsapp">{{ ___('WhatsApp number') }}</label>
    <input type="text" class="ui-input" id="blk-call-whatsapp" name="config[whatsapp]" data-field="config.whatsapp" maxlength="32">
    <div class="ui-check">
        <input type="hidden" name="config[show][whatsapp]" value="0">
        <input type="checkbox" role="switch" id="call-show-whatsapp"
               name="config[show][whatsapp]" value="1" data-field="config.show.whatsapp" data-default-checked>
        <label class="ui-small" for="call-show-whatsapp">{{ ___('Show WhatsApp button') }}</label>
    </div>
</div>
<div data-group data-types="call">
    <label class="ui-label" for="blk-call-sms">{{ ___('SMS number') }}</label>
    <input type="text" class="ui-input" id="blk-call-sms" name="config[sms]" data-field="config.sms" maxlength="32">
    <div class="ui-check">
        <input type="hidden" name="config[show][sms]" value="0">
        <input type="checkbox" role="switch" id="call-show-sms"
               name="config[show][sms]" value="1" data-field="config.show.sms" data-default-checked>
        <label class="ui-small" for="call-show-sms">{{ ___('Show SMS button') }}</label>
    </div>
</div>

{{-- A3 reservation — mode (URL uses the shared URL field above) + WhatsApp
     prefill + a party-size/note line. --}}
<div data-group data-types="reservation">
    <label class="ui-label" for="blk-reservation-mode">{{ ___('Reservation method') }}</label>
    <select class="ui-input" id="blk-reservation-mode" name="config[mode]" data-field="config.mode">
        <option value="url">{{ ___('Link (URL above)') }}</option>
        <option value="whatsapp">{{ ___('WhatsApp message') }}</option>
    </select>
</div>
<div data-group data-types="reservation">
    <label class="ui-label" for="blk-reservation-whatsapp">{{ ___('WhatsApp number') }}</label>
    <input type="text" class="ui-input" id="blk-reservation-whatsapp" name="config[whatsapp]" data-field="config.whatsapp" maxlength="32">
</div>
<div data-group data-types="reservation">
    <label class="ui-label" for="blk-reservation-party">{{ ___('Party size / note') }}</label>
    <input type="text" class="ui-input" id="blk-reservation-party" name="config[party_note]" data-field="config.party_note" maxlength="160"
           placeholder="{{ ___('e.g. Parties of 6+ call the restaurant directly') }}">
</div>

{{-- A4 reviews — platform + SSR star rating + count + a show-stars toggle
     (URL, "Leave a review", reuses the shared URL field above). --}}
<div data-group data-types="reviews">
    <label class="ui-label" for="blk-reviews-platform">{{ ___('Platform') }}</label>
    <select class="ui-input" id="blk-reviews-platform" name="config[platform]" data-field="config.platform">
        <option value="google">Google</option>
        <option value="yelp">Yelp</option>
        <option value="tripadvisor">TripAdvisor</option>
        <option value="other">{{ ___('Other') }}</option>
    </select>
</div>
<div data-group data-types="reviews">
    <label class="ui-label" for="blk-reviews-rating">{{ ___('Rating') }}</label>
    <input type="number" class="ui-input" id="blk-reviews-rating" name="config[rating]" data-field="config.rating" min="0" max="5" step="0.1">
</div>
<div data-group data-types="reviews">
    <label class="ui-label" for="blk-reviews-count">{{ ___('Review count') }}</label>
    <input type="number" class="ui-input" id="blk-reviews-count" name="config[count]" data-field="config.count" min="0" step="1">
</div>
<div class="ui-cluster ui-cluster--bottom" data-group data-types="reviews">
    <div class="ui-check">
        <input type="hidden" name="config[show_stars]" value="0">
        <input type="checkbox" role="switch" id="reviews-show-stars"
               name="config[show_stars]" value="1" data-field="config.show_stars" data-default-checked>
        <label for="reviews-show-stars">{{ ___('Show stars') }}</label>
    </div>
</div>

{{-- A5 delivery — grouped ordering/aggregator buttons (Name|URL pairs reuse
     the shared textarea above) + a list/grid layout choice. --}}
<div data-group data-types="delivery">
    <label class="ui-label" for="blk-delivery-layout">{{ ___('Layout') }}</label>
    <select class="ui-input" id="blk-delivery-layout" name="config[layout]" data-field="config.layout">
        <option value="list">{{ ___('List') }}</option>
        <option value="grid">{{ ___('Grid') }}</option>
    </select>
</div>

{{-- A6 giftcard — auto (surfaces the store's existing gift-card feature) or a
     manual URL (shared field above). --}}
<div data-group data-types="giftcard">
    <label class="ui-label" for="blk-giftcard-mode">{{ ___('Link') }}</label>
    <select class="ui-input" id="blk-giftcard-mode" name="config[mode]" data-field="config.mode">
        <option value="auto">{{ ___('Use my store’s gift card page') }}</option>
        <option value="url">{{ ___('Custom link (URL above)') }}</option>
    </select>
</div>

{{-- A7 loyalty — auto (wallet-pass seam) or a manual URL (shared field above). --}}
<div data-group data-types="loyalty">
    <label class="ui-label" for="blk-loyalty-mode">{{ ___('Link') }}</label>
    <select class="ui-input" id="blk-loyalty-mode" name="config[mode]" data-field="config.mode">
        <option value="auto">{{ ___('Use my store’s loyalty program') }}</option>
        <option value="url">{{ ___('Custom link (URL above)') }}</option>
    </select>
</div>
<div data-group data-types="loyalty">
    <label class="ui-label" for="blk-loyalty-provider">{{ ___('Provider') }}</label>
    <select class="ui-input" id="blk-loyalty-provider" name="config[provider]" data-field="config.provider">
        <option value="wallet">{{ ___('Wallet pass') }}</option>
        <option value="other">{{ ___('Other') }}</option>
    </select>
</div>

{{-- A8 gallery — photos (Caption|URL pairs reuse the shared textarea above)
     + layout + aspect ratio. --}}
<div data-group data-types="gallery">
    <label class="ui-label" for="blk-gallery-layout">{{ ___('Layout') }}</label>
    <select class="ui-input" id="blk-gallery-layout" name="config[layout]" data-field="config.layout">
        <option value="carousel">{{ ___('Carousel') }}</option>
        <option value="grid">{{ ___('Grid') }}</option>
    </select>
</div>
<div data-group data-types="gallery">
    <label class="ui-label" for="blk-gallery-ratio">{{ ___('Photo ratio') }}</label>
    <select class="ui-input" id="blk-gallery-ratio" name="config[ratio]" data-field="config.ratio">
        <option value="1:1">1:1</option>
        <option value="4:3">4:3</option>
        <option value="16:9">16:9</option>
    </select>
</div>

{{-- A9 faq — questions/answers (Question|Answer pairs reuse the shared
     textarea above). No extra fields. --}}

{{-- A10 event — title/date/location + optional inline capture (URL, the RSVP
     CTA, reuses the shared field above). --}}
<div data-group data-types="event">
    <label class="ui-label" for="blk-event-title">{{ ___('Title') }}</label>
    <input type="text" class="ui-input" id="blk-event-title" name="config[title]" data-field="config.title" maxlength="120">
</div>
<div data-group data-types="event">
    <label class="ui-label" for="blk-event-date">{{ ___('Date & time') }}</label>
    <input type="datetime-local" class="ui-input" id="blk-event-date" name="config[date]" data-field="config.date">
</div>
<div data-group data-types="event">
    <label class="ui-label" for="blk-event-location">{{ ___('Location') }}</label>
    <input type="text" class="ui-input" id="blk-event-location" name="config[location]" data-field="config.location" maxlength="191">
</div>
<div class="ui-cluster ui-cluster--bottom" data-group data-types="event">
    <div class="ui-check">
        <input type="checkbox" role="switch" id="event-capture"
               name="config[capture]" value="1" data-field="config.capture">
        <label for="event-capture">{{ ___('Collect RSVPs (email capture)') }}</label>
    </div>
</div>

{{-- A11 tipjar — mode (URL reuses the shared field above) + up to 4 preset
     amounts + currency. PRO-gated server-side (SPEC §8); the lock in the add
     tray (C7) is cosmetic only. --}}
<div data-group data-types="tipjar">
    <label class="ui-label" for="blk-tipjar-mode">{{ ___('Tipping method') }}</label>
    <select class="ui-input" id="blk-tipjar-mode" name="config[mode]" data-field="config.mode">
        <option value="url">{{ ___('Link (URL above)') }}</option>
        <option value="amounts">{{ ___('Preset amounts') }}</option>
    </select>
</div>
<div data-group data-types="tipjar">
    <label class="ui-label" for="blk-tipjar-currency">{{ ___('Currency') }}</label>
    <input type="text" class="ui-input" id="blk-tipjar-currency" name="config[currency]" data-field="config.currency" maxlength="8" placeholder="SAR">
</div>
<div data-group data-types="tipjar">
    <div class="ui-label">{{ ___('Preset amounts') }}</div>
    <div class="ui-form-grid">
        @for($i = 0; $i < 4; $i++)
            <div>
                <input type="number" class="ui-input" name="config[amounts][]" data-field="config.amounts.{{ $i }}" min="0" step="1">
            </div>
        @endfor
    </div>
</div>

{{-- per-locale content editor (B8): only when the store has >1 enabled locale.
     The DEFAULT locale writes the base label/config above; each OTHER locale
     writes config._i18n[locale][field]. Empty override → falls back to base. --}}
@php $overrideLocales = \Juwri\Core\Support\ViewHelpers::otherLocales($enabledLocales ?? [], $defaultLocale ?? 'en'); @endphp
@if(count($overrideLocales) > 0)
    <div class="ui-form-grid__full" data-i18n-section>
        <div class="bio-i18n ui-bordered">
            <div class="ui-cluster">
                <span class="ui-cluster ui-cluster--center ui-inline ui-text-accent" style="width:26px;height:26px;"><x-ui.shell.nav-icon glyph="language" /></span>
                <h4 class="ui-section-title">{{ ___('Translations') }}</h4>
                <span class="ui-text-2 ui-tag" data-tone="neutral">{{ count($overrideLocales) }}</span>
                <span class="ui-muted ui-ms-auto ui-hidden">{{ ___('Blank = falls back to the default language') }}</span>
            </div>

            {{-- locale tab bar: pick a language, edit only its fields --}}
            <div class="ui-cluster ui-scroll bio-i18n-tabs" role="tablist" data-i18n-tabs>
                @foreach($overrideLocales as $i => $loc)
                    <button type="button" class="ui-btn ui-btn--sm ui-relative ui-caps ui-strong {{ $i === 0 ? '' : 'ui-btn--secondary' }}"
                            data-i18n-tab="{{ $loc }}" role="tab" aria-selected="{{ $i === 0 ? 'true' : 'false' }}" style="letter-spacing:.03em;">
                        {{ $loc }}
                        <span class="ui-absolute ui-top-0 ui-hidden" style="inset-inline-end:-4px" data-i18n-dot="{{ $loc }}"><span class="ui-visually-hidden">{{ ___('Has content') }}</span></span>
                    </button>
                @endforeach
            </div>

            {{-- one panel per locale — only the active one is shown --}}
            @foreach($overrideLocales as $i => $loc)
                <div class="bio-i18n-panel {{ $i === 0 ? '' : 'ui-hidden' }}" data-i18n-panel="{{ $loc }}">
                    <div class="ui-form-grid">
                        <div data-group>
                            <label class="ui-label ui-label--sm" for="blk-i18n-{{ $loc }}-label">{{ ___('Label') }}</label>
                            <input type="text" class="ui-input ui-input--sm" id="blk-i18n-{{ $loc }}-label" name="i18n[{{ $loc }}][label]" data-i18n-field data-i18n-loc="{{ $loc }}" data-i18n-key="label" maxlength="191">
                        </div>
                        <div data-group data-types="announcement">
                            <label class="ui-label ui-label--sm" for="blk-i18n-{{ $loc }}-text">{{ ___('Announcement text') }}</label>
                            <input type="text" class="ui-input ui-input--sm" id="blk-i18n-{{ $loc }}-text" name="i18n[{{ $loc }}][text]" data-i18n-field data-i18n-loc="{{ $loc }}" data-i18n-key="text" maxlength="280">
                        </div>
                        <div data-group data-types="featured_dish">
                            <label class="ui-label ui-label--sm" for="blk-i18n-{{ $loc }}-title">{{ ___('Title') }}</label>
                            <input type="text" class="ui-input ui-input--sm" id="blk-i18n-{{ $loc }}-title" name="i18n[{{ $loc }}][title]" data-i18n-field data-i18n-loc="{{ $loc }}" data-i18n-key="title" maxlength="120">
                        </div>
                        <div data-group data-types="featured_dish">
                            <label class="ui-label ui-label--sm" for="blk-i18n-{{ $loc }}-subtitle">{{ ___('Subtitle') }}</label>
                            <input type="text" class="ui-input ui-input--sm" id="blk-i18n-{{ $loc }}-subtitle" name="i18n[{{ $loc }}][subtitle]" data-i18n-field data-i18n-loc="{{ $loc }}" data-i18n-key="subtitle" maxlength="160">
                        </div>
                        <div data-group data-types="featured_dish">
                            <label class="ui-label ui-label--sm" for="blk-i18n-{{ $loc }}-schedule-label">{{ ___('Schedule label') }}</label>
                            <input type="text" class="ui-input ui-input--sm" id="blk-i18n-{{ $loc }}-schedule-label" name="i18n[{{ $loc }}][schedule_label]" data-i18n-field data-i18n-loc="{{ $loc }}" data-i18n-key="schedule_label" maxlength="60">
                        </div>
                        <div data-group data-types="menu_item featured_dish">
                            <label class="ui-label ui-label--sm" for="blk-i18n-{{ $loc }}-price-label">{{ ___('Price label') }}</label>
                            <input type="text" class="ui-input ui-input--sm" id="blk-i18n-{{ $loc }}-price-label" name="i18n[{{ $loc }}][price_label]" data-i18n-field data-i18n-loc="{{ $loc }}" data-i18n-key="price_label" maxlength="40">
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
