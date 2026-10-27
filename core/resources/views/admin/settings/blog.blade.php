{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section id="quick_blog" role="tabpanel" aria-labelledby="tab-quick_blog" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('Blog Settings')">
            <div class="ui-form-grid">
                <x-ui.switch name="blog_enable" off="0" :checked="($settings->blog_enable ?? '') == '1'" :label="___('Blog')" />
                <x-ui.switch name="show_blog_home" off="0" :checked="($settings->show_blog_home ?? '') == '1'" :label="___('Show Blog On Home Page')" />
                <x-ui.switch name="blog_comment_enable" off="0" :checked="($settings->blog_comment_enable ?? '') == '1'" :label="___('Blog Commenting')" />
                <x-ui.switch name="blog_banner" off="0" :checked="($settings->blog_banner ?? '') == '1'" :label="___('Blog Banner Image')" />
                <x-ui.field type="number" name="blog_page_limit" :label="___('Number of Blogs on blog page')" :value="$settings->blog_page_limit ?? 8" />
                <x-ui.field type="select" name="blog_comment_approval" :label="___('Comment Approval').' *'" :value="(string) ($settings->blog_comment_approval ?? '1')" :options="[
                    '1' => ___('Disable Auto Approve Comments'), '2' => ___('Auto Approve Login Users Comments'), '3' => ___('Auto Approve All Comments'),
                ]" />
                <x-ui.field type="select" name="blog_comment_user" :label="___('Who Can Comment').' *'" :value="(string) ($settings->blog_comment_user ?? '1')"
                            :options="['1' => ___('Everyone'), '0' => ___('Only Login Users')]" :help="___('Non-login users have to enter their name and email address.')" />
            </div>
            @include('admin.settings._save', ['marker' => 'blog_settings'])
        </x-ui.card>
    </form>
</section>
