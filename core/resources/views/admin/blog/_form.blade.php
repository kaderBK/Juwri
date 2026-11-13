{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Blog post page form shared by create and edit. $blog is null on create. --}}
@php
    $__picked = $blog ? $blog->categories->pluck('id')->all() : array_map('intval', (array) old('category', []));
    $__locale = old('locale', $blog?->locale);
@endphp
<form action="{{ $blog ? route('admin.blogs.update', $blog->id) : route('admin.blogs.store') }}" method="POST" enctype="multipart/form-data">
    @csrf
    @if ($blog) @method('PUT') @endif
    <div class="ui-columns ui-columns--wide-first">
        <x-ui.card :title="___('Content')">
            <div class="ui-stack">
                <x-ui.field name="title" :label="___('Title').' *'" :value="$blog?->title" required :autofocus="! $blog" />
                <x-ui.field name="slug" :label="___('Slug')" :value="$blog?->slug" />
                <x-ui.image-field name="image" :label="___('Image').' *'" :size="120" :hint="___('Allowed JPG, JPEG or PNG.')"
                    :src="$blog ? asset('storage/blog/'.$blog->image) : null" />
                <div class="ui-field">
                    <label class="ui-field__label" for="blog-description">{{ $blog ? ___('Description') : ___('Content') }} *</label>
                    <textarea id="blog-description" name="description" rows="12" data-ui-rich>{{ old('description', $blog?->description) }}</textarea>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card :title="___('Publish')">
            <div class="ui-stack">
                <x-ui.field type="select" name="status" :label="___('Status')" :value="$blog?->status"
                    :options="['publish' => ___('Publish'), 'pending' => ___('Pending')]"
                    :help="___('Select Pending if you want to hide this from the frontend')" />
                <div class="ui-field">
                    <label class="ui-field__label" for="blog-category">{{ ___('Category') }} *</label>
                    <select id="blog-category" name="category[]" multiple data-ui-multi>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected(in_array($category->id, $__picked))>{{ $category->title }}</option>
                        @endforeach
                    </select>
                </div>

                <p class="ui-section-title">{{ ___('SEO') }}</p>
                <div class="ui-field">
                    <label class="ui-field__label" for="blog-locale">{{ ___('Post language (SEO)') }}</label>
                    <div class="ui-field__control">
                        <select id="blog-locale" name="locale">
                            <option value="">{{ ___('All languages (legacy)') }}</option>
                            @foreach (['ar', 'en', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $__code)
                                <option value="{{ $__code }}" @selected($__locale == $__code)>{{ $__code }}</option>
                            @endforeach
                        </select>
                        <x-ui.icon name="chevron-down" size="16" class="ui-field__chevron" />
                    </div>
                    <span class="ui-field__help">{{ ___('Set the language of this post. Leave as All to show it on every locale.') }}</span>
                </div>
                <x-ui.field name="translation_group" :label="___('Translation group (SEO)')" :value="$blog?->translation_group"
                    :placeholder="___('Same value across translations of one article to link them via hreflang')" />
                <x-ui.field type="textarea" name="meta_description" rows="2" maxlength="500" :label="___('Meta description (SEO)')" :value="$blog?->meta_description"
                    :placeholder="___('Shown as the search-result snippet. Leave empty to use the first lines of the post.')" />
                <x-ui.field type="textarea" name="tags" rows="2" :label="___('Tags')" :value="$blog?->tags"
                    :placeholder="___('Enter tags separated by comma')" required />
                <x-ui.button type="submit" data-ui-busy>{{ ___('Submit') }}</x-ui.button>
            </div>
        </x-ui.card>
    </div>
</form>
