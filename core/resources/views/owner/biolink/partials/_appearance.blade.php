{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- bio-t15 C2 · Appearance tab — every control in SPEC §3's frozen appearance
     schema (bio_settings.appearance). Posts through the EXISTING
     `biolink.settings.save` route (no new route, no controller edit) — Lane C
     adds no server code. Since that controller action rebuilds the FULL
     settings array from whatever fields are present in the request, this form
     also carries hidden mirrors of enabled/preset/mode/sections/branch_pages
     (read from $bioSettings, already passed into the builder view) so an
     appearance-only save never resets the rest of the page's settings.

     `appearance[…]` fields are inert today (the controller doesn't read that
     key yet — Lane B's job) but harmless: Laravel's `$request->validate()`
     silently ignores unlisted keys, so this form is safe to ship ahead of B. --}}

@php
    $ap = $bioSettings['appearance'] ?? [];
    $apBg = $ap['bg_gradient'] ?? [];
    $apHide = $ap['hide'] ?? [];
    $apOg = $ap['og'] ?? [];
    // SPEC §4 — frozen curated font list (display + body pickers).
    $curatedFonts = [
        'Inter', 'Poppins', 'Montserrat', 'Playfair Display', 'Lora', 'Merriweather',
        'Space Grotesk', 'DM Sans', 'DM Serif Display', 'Manrope', 'Nunito', 'Work Sans',
        'Fraunces', 'Bricolage Grotesque', 'Sora', 'Rubik', 'Cairo', 'Tajawal',
        'IBM Plex Sans', 'Bebas Neue',
    ];
    $arabicFonts = ['Cairo', 'Tajawal'];
    // SPEC §5 — the 8 shipped tileable pattern slugs.
    $bgPatterns = ['kraft', 'dots', 'arches', 'grid', 'waves', 'confetti', 'zellige', 'topo'];
@endphp

<form data-appearance-form action="{{ route('biolink.settings.save') }}" method="post" class="ui-form-grid">
    @csrf
    <input type="hidden" name="enabled" value="{{ !empty($bioSettings['enabled']) ? '1' : '0' }}">
    <input type="hidden" name="preset" value="{{ $bioSettings['preset'] ?? 'classic_flat' }}">
    <input type="hidden" name="mode" value="{{ $bioSettings['mode'] ?? 'menu_first' }}">
    <input type="hidden" name="branch_pages" value="{{ !empty($bioSettings['branch_pages']) ? '1' : '0' }}">
    @foreach(($bioSettings['sections'] ?? []) as $secKey => $secVal)
        <input type="hidden" name="sections[{{ $secKey }}]" value="{{ $secVal ? '1' : '0' }}">
    @endforeach

    {{-- Profile — the visible name, tagline and avatar (drive .bio-name / .bio-profile
         on the public page). Before this there was no field for either: the page
         always fell back to the store name + logo. --}}
    <div class="ui-form-grid__full">
        <x-ui.card :title="___('Profile')">
            <div class="ui-form-grid">
                <div>
                    <label class="ui-label">{{ ___('Display name') }}</label>
                    <input type="text" class="ui-input" name="appearance[display_name]" data-appearance-field="display_name" maxlength="100" value="{{ $ap['display_name'] ?? '' }}" placeholder="{{ ___('Your store or brand name') }}">
                    <small class="ui-text-2">{{ ___('Shown as the page heading. Leave empty to use your store name.') }}</small>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Tagline') }}</label>
                    <input type="text" class="ui-input" name="appearance[tagline]" data-appearance-field="tagline" maxlength="160" value="{{ $ap['tagline'] ?? '' }}" placeholder="{{ ___('A short line under your name') }}">
                </div>
                <div class="ui-form-grid__full"
                     x-data="imagePickerField({ initial: @js($ap['avatar_image'] ?? ''), url: @js(!empty($ap['avatar_image']) ? media_url('storage/menu/', $ap['avatar_image'], (int) $store->id) : ''), target: 'bio-avatar', storeId: {{ (int) $store->id }}, folder: 'bio' })">
                    <label class="ui-label ui-block">{{ ___('Profile picture') }}</label>
                    <input type="hidden" name="appearance[avatar_image]" data-appearance-field="avatar_image" :value="filename">
                    <div class="ui-cluster">
                        <img x-show="url" :src="url" alt="" x-cloak style="width:56px;height:56px;object-fit:cover;border-radius:50%;border:1px solid var(--bs-border-color);">
                        <button type="button" class="ui-cluster ui-inline ui-btn ui-btn--sm ui-btn--secondary" @click="choose()">
                            <x-ui.shell.nav-icon glyph="picture" />
                            <span x-show="!filename">{{ ___('Choose image') }}</span><span x-show="filename" x-cloak>{{ ___('Change image') }}</span>
                        </button>
                        <button type="button" class="ui-btn ui-btn--sm ui-btn--secondary" x-show="filename" x-cloak @click="clear()">{{ ___('Remove') }}</button>
                    </div>
                    <small class="ui-text-2 ui-block">{{ ___('Pick from your Media library or generate one in the image studio. Leave empty to use your store logo.') }}</small>
                </div>
            </div>
        </x-ui.card>
    </div>

    <div class="ui-form-grid__full">
        <x-ui.card :title="___('Colors')">
            <div class="ui-form-grid">
                <div>
                    <label class="ui-label ui-small">{{ ___('Accent') }}</label>
                    <input type="color" class="ui-input ui-w-full" name="appearance[accent]" data-appearance-field="accent" value="{{ $ap['accent'] ?? '#6f4dff' }}">
                </div>
                <div>
                    <label class="ui-label ui-small">{{ ___('Accent text') }}</label>
                    <input type="color" class="ui-input ui-w-full" name="appearance[accent_ink]" data-appearance-field="accent_ink" value="{{ $ap['accent_ink'] ?? '#ffffff' }}">
                </div>
                <div>
                    <label class="ui-label ui-small">{{ ___('Text') }}</label>
                    <input type="color" class="ui-input ui-w-full" name="appearance[ink]" data-appearance-field="ink" value="{{ $ap['ink'] ?? '#141414' }}">
                </div>
                <div>
                    <label class="ui-label ui-small">{{ ___('Muted text') }}</label>
                    <input type="color" class="ui-input ui-w-full" name="appearance[muted]" data-appearance-field="muted" value="{{ $ap['muted'] ?? '#6b6b6b' }}">
                </div>
                <div>
                    <label class="ui-label ui-small">{{ ___('Button background') }}</label>
                    <input type="color" class="ui-input ui-w-full" name="appearance[btn_bg]" data-appearance-field="btn_bg" value="{{ $ap['btn_bg'] ?? '#ffffff' }}">
                </div>
                <div>
                    <label class="ui-label ui-small">{{ ___('Button text') }}</label>
                    <input type="color" class="ui-input ui-w-full" name="appearance[btn_fg]" data-appearance-field="btn_fg" value="{{ $ap['btn_fg'] ?? '#141414' }}">
                </div>
            </div>
            <p class="ui-muted">{{ ___('Tip: keep enough contrast between your text and background colors — very light text on a light background is hard to read.') }}</p>
        </x-ui.card>
    </div>

    <div class="ui-form-grid__full">
        <x-ui.card :title="___('Buttons')">
            <div class="ui-form-grid">
                <div>
                    <label class="ui-label">{{ ___('Style') }}</label>
                    <select class="ui-input" name="appearance[btn_style]" data-appearance-field="btn_style">
                        <option value="fill" @selected(($ap['btn_style'] ?? 'fill') === 'fill')>{{ ___('Fill') }}</option>
                        <option value="outline" @selected(($ap['btn_style'] ?? '') === 'outline')>{{ ___('Outline') }}</option>
                        <option value="soft" @selected(($ap['btn_style'] ?? '') === 'soft')>{{ ___('Soft') }}</option>
                    </select>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Corner shape') }}</label>
                    <select class="ui-input" name="appearance[btn_radius]" data-appearance-field="btn_radius">
                        <option value="sharp" @selected(($ap['btn_radius'] ?? '') === 'sharp')>{{ ___('Sharp') }}</option>
                        <option value="round" @selected(($ap['btn_radius'] ?? 'round') === 'round')>{{ ___('Rounded') }}</option>
                        <option value="pill" @selected(($ap['btn_radius'] ?? '') === 'pill')>{{ ___('Pill') }}</option>
                    </select>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Alignment') }}</label>
                    <select class="ui-input" name="appearance[btn_align]" data-appearance-field="btn_align">
                        <option value="left" @selected(($ap['btn_align'] ?? '') === 'left')>{{ ___('Left') }}</option>
                        <option value="center" @selected(($ap['btn_align'] ?? 'center') === 'center')>{{ ___('Center') }}</option>
                    </select>
                </div>
                <div class="ui-check">
                    <input type="hidden" name="appearance[show_icons]" value="0">
                    <input type="checkbox" role="switch" id="ap-show-icons" name="appearance[show_icons]" value="1" data-appearance-field="show_icons" @checked($ap['show_icons'] ?? true)>
                    <label for="ap-show-icons">{{ ___('Show link icons') }}</label>
                </div>
                <div class="ui-check">
                    <input type="hidden" name="appearance[show_arrows]" value="0">
                    <input type="checkbox" role="switch" id="ap-show-arrows" name="appearance[show_arrows]" value="1" data-appearance-field="show_arrows" @checked($ap['show_arrows'] ?? true)>
                    <label for="ap-show-arrows">{{ ___('Show link arrows') }}</label>
                </div>
            </div>
        </x-ui.card>
    </div>

    <div class="ui-form-grid__full">
        <x-ui.card :title="___('Background')">
            <div class="ui-form-grid">
                <div>
                    <label class="ui-label">{{ ___('Type') }}</label>
                    <select class="ui-input" name="appearance[bg_type]" data-appearance-field="bg_type" data-bg-type-select>
                        <option value="preset" @selected(($ap['bg_type'] ?? 'preset') === 'preset')>{{ ___('Design default') }}</option>
                        <option value="solid" @selected(($ap['bg_type'] ?? '') === 'solid')>{{ ___('Solid color') }}</option>
                        <option value="gradient" @selected(($ap['bg_type'] ?? '') === 'gradient')>{{ ___('Gradient') }}</option>
                        <option value="pattern" @selected(($ap['bg_type'] ?? '') === 'pattern')>{{ ___('Pattern') }}</option>
                        <option value="image" @selected(($ap['bg_type'] ?? '') === 'image')>{{ ___('Image') }} — {{ ___('PRO') }}</option>
                        <option value="video" disabled>{{ ___('Video') }} — {{ ___('Coming soon') }}</option>
                    </select>
                </div>

                <div data-bg-group data-bg-types="solid">
                    <label class="ui-label">{{ ___('Solid color') }}</label>
                    <input type="color" class="ui-input ui-w-full" name="appearance[bg_solid]" data-appearance-field="bg_solid" value="{{ $ap['bg_solid'] ?? '#111111' }}">
                </div>

                <div data-bg-group data-bg-types="gradient">
                    <label class="ui-label">{{ ___('From') }}</label>
                    <input type="color" class="ui-input ui-w-full" name="appearance[bg_gradient][from]" data-appearance-field="bg_gradient.from" value="{{ $apBg['from'] ?? '#141414' }}">
                </div>
                <div data-bg-group data-bg-types="gradient">
                    <label class="ui-label">{{ ___('To') }}</label>
                    <input type="color" class="ui-input ui-w-full" name="appearance[bg_gradient][to]" data-appearance-field="bg_gradient.to" value="{{ $apBg['to'] ?? '#3a3a3a' }}">
                </div>
                <div data-bg-group data-bg-types="gradient">
                    <label class="ui-label">{{ ___('Angle') }}</label>
                    <input type="number" class="ui-input" name="appearance[bg_gradient][angle]" data-appearance-field="bg_gradient.angle" min="0" max="360" value="{{ $apBg['angle'] ?? 175 }}">
                </div>
                <div data-bg-group data-bg-types="gradient">
                    <label class="ui-label">{{ ___('Shape') }}</label>
                    <select class="ui-input" name="appearance[bg_gradient][type]" data-appearance-field="bg_gradient.type">
                        <option value="linear" @selected(($apBg['type'] ?? 'linear') === 'linear')>{{ ___('Linear') }}</option>
                        <option value="radial" @selected(($apBg['type'] ?? '') === 'radial')>{{ ___('Radial') }}</option>
                    </select>
                </div>

                <div class="ui-form-grid__full" data-bg-group data-bg-types="pattern">
                    <label class="ui-label">{{ ___('Pattern') }}</label>
                    <div class="ui-cluster">
                        @foreach($bgPatterns as $pattern)
                            <div class="ui-check">
                                <input type="radio" id="bg-pattern-{{ $pattern }}" name="appearance[bg_pattern]" value="{{ $pattern }}" data-appearance-field="bg_pattern" @checked(($ap['bg_pattern'] ?? 'kraft') === $pattern)>
                                <label class="ui-small ui-capitalize" for="bg-pattern-{{ $pattern }}">{{ $pattern }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div data-bg-group data-bg-types="image"
                     x-data="imagePickerField({ initial: @js($ap['bg_image'] ?? ''), url: @js(!empty($ap['bg_image']) ? media_url('storage/menu/', $ap['bg_image'], (int) $store->id) : ''), target: 'bio-bg', storeId: {{ (int) $store->id }}, folder: 'bio' })">
                    <label class="ui-label ui-block">{{ ___('Background image') }} <span class="ui-tag" data-tone="warning">{{ ___('PRO') }}</span></label>
                    <input type="hidden" name="appearance[bg_image]" data-appearance-field="bg_image" :value="filename">
                    <div class="ui-cluster">
                        <img x-show="url" :src="url" alt="" x-cloak style="width:64px;height:64px;object-fit:cover;border-radius:.6rem;border:1px solid var(--bs-border-color);">
                        <button type="button" class="ui-cluster ui-inline ui-btn ui-btn--sm ui-btn--secondary" @click="choose()">
                            <x-ui.shell.nav-icon glyph="picture" />
                            <span x-show="!filename">{{ ___('Choose image') }}</span><span x-show="filename" x-cloak>{{ ___('Change image') }}</span>
                        </button>
                        <button type="button" class="ui-btn ui-btn--sm ui-btn--secondary" x-show="filename" x-cloak @click="clear()">{{ ___('Remove') }}</button>
                    </div>
                    <small class="ui-text-2 ui-block">{{ ___('Pick from your Media library or generate one in the image studio.') }}</small>
                </div>
                <div data-bg-group data-bg-types="image">
                    <label class="ui-label">{{ ___('Dim overlay') }}</label>
                    <input type="range" class="ui-range" name="appearance[bg_scrim]" data-appearance-field="bg_scrim" min="0" max="100" value="{{ $ap['bg_scrim'] ?? 30 }}">
                </div>

                <div class="ui-form-grid__full bio-ap-deferred" data-bg-group data-bg-types="video">
                    <p class="ui-muted"><x-ui.shell.nav-icon glyph="lock" />{{ ___('Video backgrounds are coming soon — contact support to be notified.') }}</p>
                </div>
            </div>
        </x-ui.card>
    </div>

    <div class="ui-form-grid__full">
        <x-ui.card :title="___('Fonts')">
            <div class="ui-form-grid">
                <div>
                    <label class="ui-label">{{ ___('Display font (headings)') }}</label>
                    <select class="ui-input" name="appearance[font_display]" data-appearance-field="font_display">
                        @foreach($curatedFonts as $font)
                            <option value="{{ $font }}" @selected(($ap['font_display'] ?? 'Poppins') === $font)>{{ $font }}@if(in_array($font, $arabicFonts, true)) ({{ ___('Arabic') }})@endif</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Body font') }}</label>
                    <select class="ui-input" name="appearance[font_body]" data-appearance-field="font_body">
                        @foreach($curatedFonts as $font)
                            <option value="{{ $font }}" @selected(($ap['font_body'] ?? 'Inter') === $font)>{{ $font }}@if(in_array($font, $arabicFonts, true)) ({{ ___('Arabic') }})@endif</option>
                        @endforeach
                    </select>
                </div>
                <div class="ui-form-grid__full bio-ap-deferred">
                    <label class="ui-text-2 ui-label">{{ ___('Upload your own font') }}</label>
                    <input type="file" class="ui-input" disabled>
                    <small class="ui-text-2"><x-ui.shell.nav-icon glyph="lock" />{{ ___('Coming soon — contact support.') }}</small>
                </div>
            </div>
        </x-ui.card>
    </div>

    <div class="ui-form-grid__full">
        <x-ui.card :title="___('Header')">
            <div class="ui-form-grid">
                <div>
                    <label class="ui-label">{{ ___('Avatar shape') }}</label>
                    <select class="ui-input" name="appearance[avatar_shape]" data-appearance-field="avatar_shape">
                        <option value="circle" @selected(($ap['avatar_shape'] ?? 'circle') === 'circle')>{{ ___('Circle') }}</option>
                        <option value="rounded" @selected(($ap['avatar_shape'] ?? '') === 'rounded')>{{ ___('Rounded square') }}</option>
                        <option value="square" @selected(($ap['avatar_shape'] ?? '') === 'square')>{{ ___('Square') }}</option>
                    </select>
                </div>
                <div
                     x-data="imagePickerField({ initial: @js($ap['cover_image'] ?? ''), url: @js(!empty($ap['cover_image']) ? media_url('storage/menu/', $ap['cover_image'], (int) $store->id) : ''), target: 'bio-cover', storeId: {{ (int) $store->id }}, folder: 'bio' })">
                    <label class="ui-label ui-block">{{ ___('Cover image') }}</label>
                    <input type="hidden" name="appearance[cover_image]" data-appearance-field="cover_image" :value="filename">
                    <div class="ui-cluster">
                        <img x-show="url" :src="url" alt="" x-cloak style="width:96px;height:54px;object-fit:cover;border-radius:.5rem;border:1px solid var(--bs-border-color);">
                        <button type="button" class="ui-cluster ui-inline ui-btn ui-btn--sm ui-btn--secondary" @click="choose()">
                            <x-ui.shell.nav-icon glyph="picture" />
                            <span x-show="!filename">{{ ___('Choose image') }}</span><span x-show="filename" x-cloak>{{ ___('Change image') }}</span>
                        </button>
                        <button type="button" class="ui-btn ui-btn--sm ui-btn--secondary" x-show="filename" x-cloak @click="clear()">{{ ___('Remove') }}</button>
                    </div>
                    <div class="ui-check">
                        <input type="checkbox" role="switch" id="ap-show-cover" name="appearance[show_cover]" value="1" data-appearance-field="show_cover" @checked($ap['show_cover'] ?? false)>
                        <label class="ui-small" for="ap-show-cover">{{ ___('Show cover image') }}</label>
                    </div>
                </div>
                <div class="ui-form-grid__full">
                    <label class="ui-label">{{ ___('Hide from the header') }}</label>
                    <div class="ui-cluster">
                        @foreach(['title' => ___('Store name'), 'tagline' => ___('Tagline'), 'status' => ___('Open/closed status'), 'verified' => ___('Verified badge')] as $hk => $hLabel)
                            <div class="ui-check">
                                <input type="checkbox" id="ap-hide-{{ $hk }}" name="appearance[hide][{{ $hk }}]" value="1" data-appearance-field="hide.{{ $hk }}" @checked($apHide[$hk] ?? false)>
                                <label class="ui-small" for="ap-hide-{{ $hk }}">{{ $hLabel }}</label>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-ui.card>
    </div>

    <div class="ui-form-grid__full">
        <x-ui.card :title="___('Sharing & SEO')">
            <div class="ui-form-grid">
                <div>
                    <label class="ui-label">{{ ___('Page title') }}</label>
                    <input type="text" class="ui-input" name="appearance[og][title]" data-appearance-field="og.title" maxlength="191" value="{{ $apOg['title'] ?? '' }}">
                </div>
                <div
                     x-data="imagePickerField({ initial: @js($apOg['image'] ?? ''), url: @js(!empty($apOg['image']) ? media_url('storage/menu/', $apOg['image'], (int) $store->id) : ''), target: 'bio-og', storeId: {{ (int) $store->id }}, folder: 'bio' })">
                    <label class="ui-label ui-block">{{ ___('Share image') }}</label>
                    <input type="hidden" name="appearance[og][image]" data-appearance-field="og.image" :value="filename">
                    <div class="ui-cluster">
                        <img x-show="url" :src="url" alt="" x-cloak style="width:96px;height:54px;object-fit:cover;border-radius:.5rem;border:1px solid var(--bs-border-color);">
                        <button type="button" class="ui-cluster ui-inline ui-btn ui-btn--sm ui-btn--secondary" @click="choose()">
                            <x-ui.shell.nav-icon glyph="picture" />
                            <span x-show="!filename">{{ ___('Choose image') }}</span><span x-show="filename" x-cloak>{{ ___('Change image') }}</span>
                        </button>
                        <button type="button" class="ui-btn ui-btn--sm ui-btn--secondary" x-show="filename" x-cloak @click="clear()">{{ ___('Remove') }}</button>
                    </div>
                    <small class="ui-text-2 ui-block">{{ ___('Used when your page is shared on social media.') }}</small>
                </div>
                <div class="ui-form-grid__full">
                    <label class="ui-label">{{ ___('Page description') }}</label>
                    <textarea class="ui-input" name="appearance[og][description]" data-appearance-field="og.description" rows="2" maxlength="300">{{ $apOg['description'] ?? '' }}</textarea>
                </div>
                <div class="ui-form-grid__full bio-ap-deferred">
                    <label class="ui-text-2 ui-label">{{ ___('Custom domain') }}</label>
                    <input type="text" class="ui-input" disabled placeholder="{{ ___('e.g. links.yourbrand.com') }}">
                    <small class="ui-text-2"><x-ui.shell.nav-icon glyph="lock" />{{ ___('Connect a domain (owner setup required) — contact support.') }}</small>
                </div>
            </div>
        </x-ui.card>
    </div>

    <div class="ui-cluster ui-cluster--between ui-form-grid__full">
        <span class="ui-muted ui-hidden" data-appearance-saved><x-ui.shell.nav-icon glyph="check-circle" />{{ ___('Applied. Changes are live on your page.') }}</span>
        <x-ui.button type="submit" icon="check" class="ui-ms-auto">{{ ___('Save appearance') }}</x-ui.button>
    </div>
</form>

{{-- Media-library / image-studio picker dialog. Defines the Alpine
     imagePickerField() factory + window.openMediaPicker() that the Appearance
     image fields above (background / cover / avatar / share) bind to. Include
     ONCE per page — this is the biolink builder's only include of it. --}}
@include('owner.media._picker')
