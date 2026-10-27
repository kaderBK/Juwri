{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__accept = '.jpg,.jpeg,.png,.webp,.svg,.gif,.avif,.bmp,.ico';
    $__hint = ___('Allowed JPG, PNG, WEBP, SVG, GIF, AVIF, BMP or ICO.');
    $__tileStyle = (string) (config('settings.brand_tile_style') ?? '1');
    $__tileImage = config('settings.brand_tile_image') ?? '';
@endphp
<section id="quick_logo_favicon" role="tabpanel" aria-labelledby="tab-quick_logo_favicon" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" data-ui-ajax>
        <x-ui.card :title="___('Logo & Favicon')">
            <div class="ui-stack">
                <div class="ui-form-grid">
                    <x-ui.image-field name="site_favicon" :label="___('Favicon Icon')" :src="\Juwri\Core\Support\ViewHelpers::adminLogoUrl($settings->site_favicon ?? '')" :accept="$__accept" :hint="$__hint" :size="80" />
                    <x-ui.image-field name="site_admin_logo" :label="___('Admin Logo')" :src="\Juwri\Core\Support\ViewHelpers::adminLogoUrl($settings->site_admin_logo ?? '')" :accept="$__accept" :hint="$__hint" :size="80" />
                    <x-ui.image-field name="site_logo" :label="___('Site Logo (Dark)')" :src="\Juwri\Core\Support\ViewHelpers::adminLogoUrl($settings->site_logo ?? '')" :accept="$__accept" :hint="$__hint" :size="80" />
                    <x-ui.image-field name="site_logo_footer" :label="___('Footer Logo (Light)')" :src="\Juwri\Core\Support\ViewHelpers::adminLogoUrl($settings->site_logo_footer ?? '')" :accept="$__accept" :hint="$__hint" :size="80" data-ui-dark-preview />
                    <x-ui.image-field name="social_share_image" :label="___('Social Share Image')" :src="\Juwri\Core\Support\ViewHelpers::adminLogoUrl($settings->social_share_image ?? '')" accept=".jpg,.jpeg,.png,.webp,.gif,.avif,.bmp"
                                      :hint="___('Allowed JPG, PNG, WEBP, GIF, AVIF or BMP.').' 600x315px.'" :size="120" />
                </div>

                <p class="ui-section-title">{{ ___('Logo background effect') }}</p>
                <p class="ui-small ui-text-2">{{ ___('The animated gradient tile shown behind your logo in the sidebar, the public site and the sign-in pages.') }}</p>
                <fieldset class="ui-tile-picks">
                    <legend class="ui-visually-hidden">{{ ___('Logo background effect') }}</legend>
                    <label class="ui-tile-pick">
                        <input type="radio" name="brand_tile_style" value="off" @checked($__tileStyle === 'off')>
                        <span class="ui-tile-pick__swatch"><x-ui.icon name="ban" size="20" /></span>
                        <small>{{ ___('Off') }}</small>
                    </label>
                    @foreach (brand_tile_presets() as $__key => $__url)
                        <label class="ui-tile-pick">
                            <input type="radio" name="brand_tile_style" value="{{ $__key }}" @checked($__tileStyle === (string) $__key)>
                            <span class="ui-tile-pick__swatch" style="background-image:url('{{ $__url }}')"></span>
                            <small>{{ ___('Gradient') }} {{ $__key }}</small>
                        </label>
                    @endforeach
                    <label class="ui-tile-pick">
                        <input type="radio" name="brand_tile_style" value="custom" @checked($__tileStyle === 'custom')>
                        <span class="ui-tile-pick__swatch" @if ($__tileImage) style="background-image:url('{{ asset('storage/logo/'.$__tileImage) }}')" @endif>
                            @unless ($__tileImage)<x-ui.icon name="upload" size="20" />@endunless
                        </span>
                        <small>{{ ___('Custom') }}</small>
                    </label>
                </fieldset>
                <div class="ui-form-grid">
                    <div class="ui-field">
                        <label class="ui-field__label" for="brand_tile_speed">{{ ___('Animation speed') }}</label>
                        <div class="ui-field__control">
                            <select name="brand_tile_speed" id="brand_tile_speed" aria-describedby="brand_tile_speed-help">
                                @foreach (brand_tile_speeds() as $__sec => $__label)
                                    <option value="{{ $__sec }}" @selected(brand_tile_speed() === (int) $__sec)>{{ ___($__label) }}@if ((int) $__sec > 0) — {{ $__sec }}s @endif</option>
                                @endforeach
                            </select>
                            <x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
                        </div>
                        <span class="ui-field__help" id="brand_tile_speed-help">{{ ___('How long one full pass of the gradient takes. Static freezes it.') }}</span>
                    </div>
                    <div class="ui-field">
                        <span class="ui-field__label">{{ ___('Upload custom background') }}</span>
                        <label class="ui-btn ui-btn--secondary" for="brand_tile_image">
                            <span class="ui-btn__icon"><x-ui.icon name="upload" size="16" /></span>{{ ___('Upload custom background') }}
                        </label>
                        <input name="brand_tile_image" type="file" id="brand_tile_image" class="ui-visually-hidden" accept=".jpg,.jpeg,.png,.webp,.svg,.gif,.avif,.bmp">
                        <span class="ui-field__help">{{ ___('Uploading a background selects the Custom option automatically.') }}</span>
                    </div>
                </div>
            </div>
            @include('admin.settings._save', ['marker' => 'logo_setting'])
        </x-ui.card>
    </form>
</section>
