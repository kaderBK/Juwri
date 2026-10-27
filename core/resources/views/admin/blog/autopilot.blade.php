{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('Blog Autopilot'))
@section('header_buttons')
    <x-ui.button variant="secondary" size="sm" icon="book-open" :href="route('admin.blogs.index')">{{ ___('All Blogs') }}</x-ui.button>
@endsection
@section('content')
    @php
        /* Stored as a csv STRING (Lane A parses csv; the Option path would
           json_encode a posted array — see LEDGER B1 note). Tolerate a legacy
           array just in case. */
        $rawLocales = @$settings->blog_autopilot_locales;
        $selectedLocales = \Juwri\Core\Support\ViewHelpers::cleanList(is_array($rawLocales) ? $rawLocales : explode(',', (string) $rawLocales));
        $currentAuthor = (string) @$settings->blog_autopilot_author;
    @endphp
<div class="ui-stack">
    {{-- ── B2: settings card (generic admin.settings.update Option path) ── --}}
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        @csrf
        <input type="hidden" name="general_setting" value="1">
        {{-- the general_setting path requires site_title; carry the current one through --}}
        <input type="hidden" name="site_title" value="{{ @$settings->site_title }}">
        <x-ui.card :title="___('Autopilot Settings')">
            <div class="ui-form-grid">
                <x-ui.switch name="blog_autopilot_enabled" id="blog_autopilot_enabled" off="0" :checked="($settings->blog_autopilot_enabled ?? null) == '1'"
                             :label="___('Enable Blog Autopilot')"
                             :description="___('Master switch. When off, the scheduler does nothing; drafting from this page still works.')" />
                <x-ui.switch name="blog_autopilot_images" id="blog_autopilot_images" off="0" :checked="($settings->blog_autopilot_images ?? null) != '0'"
                             :label="___('Generate AI hero images')"
                             :description="___('One AI image per post. Image failure never blocks the post.')" />
                <x-ui.switch name="blog_autopilot_auto" id="blog_autopilot_auto" off="0" :checked="($settings->blog_autopilot_auto ?? null) == '1'"
                             :label="___('Full-auto mode (top up topics and publish drafts)')"
                             :description="___('Off = draft mode: generated posts wait as Pending for your approval. On = the autopilot also publishes, hard-capped per week below.')" />
                <x-ui.field type="number" name="blog_autopilot_weekly_cap" id="blog_autopilot_weekly_cap" min="1" max="50"
                            :label="___('Weekly publish cap (full-auto)')"
                            :value="($settings->blog_autopilot_weekly_cap ?? null) ?: config('blog_autopilot.weekly_publish_cap')"
                            :help="___('Hard ceiling on auto-published posts per rolling 7 days.')" />
                <div class="ui-field">
                    <label class="ui-field__label" for="blog_autopilot_locales_select">{{ ___('Auto-translate published posts into') }}</label>
                    <div class="ui-field__control">
                        <select id="blog_autopilot_locales_select" multiple size="4" aria-describedby="blog_autopilot_locales_help">
                            @foreach($languages as $language)
                                <option value="{{ $language->code }}" @selected(in_array($language->code, $selectedLocales, true))>{{ $language->name }} ({{ $language->code }})</option>
                            @endforeach
                        </select>
                    </div>
                    <input type="hidden" name="blog_autopilot_locales" id="blog_autopilot_locales" value="{{ implode(',', $selectedLocales) }}">
                    <span class="ui-field__help" id="blog_autopilot_locales_help">{{ ___('Leave empty to publish in the original language only.') }}</span>
                </div>
                <x-ui.field type="select" name="blog_autopilot_author" id="blog_autopilot_author" :label="___('Post author')" :value="$currentAuthor"
                            :options="['' => ___('First admin user')] + \Juwri\Core\Support\ViewHelpers::propOptions($admins, 'id', 'name', true)"
                            :help="___('Generated posts carry this real author byline.')" />
            </div>
            <div class="ui-card__foot ui-cluster--end">
                <x-ui.button type="submit">{{ ___('Save Changes') }}</x-ui.button>
            </div>
        </x-ui.card>
    </form>

    {{-- ── AI usage — what autopilot is costing (ai_usage, feature=blog_autopilot) ── --}}
    @php
        $usageWindows = [
            'today' => ___('Today'),
            'week' => ___('Last 7 days'),
            'month' => ___('This month'),
            'all' => ___('All time'),
        ];
    @endphp
    <x-ui.card :title="___('AI usage')" id="autopilot-usage">
        @if(!empty($usage['last_at']))
            <x-slot:actions><small class="ui-text-2">{{ ___('Last run') }}: {{ date_formating($usage['last_at']) }}</small></x-slot:actions>
        @endif
        <div class="ui-stack">
            <div class="ui-kpis">
                @foreach($usageWindows as $key => $label)
                    @php $w = $usage['windows'][$key] ?? ['calls' => 0, 'tokens' => 0, 'cost' => null]; @endphp
                    <x-ui.card variant="inset" as="div">
                        <div class="ui-overline">{{ $label }}</div>
                        <div class="ui-fs-xl ui-strong ui-num">{{ number_format($w['calls']) }}</div>
                        <div class="ui-small ui-text-2">{{ ___('AI calls') }}</div>
                        <div class="ui-small ui-num">{{ number_format($w['tokens']) }} {{ ___('tokens') }}</div>
                        @if($w['cost'] !== null)
                            <div class="ui-small ui-num ui-text-success">≈ ${{ number_format($w['cost'], 2) }}</div>
                        @endif
                    </x-ui.card>
                @endforeach
            </div>

            @if(empty($usage['by_provider']))
                <p class="ui-small ui-text-2">{{ ___('No AI activity yet — usage appears here once autopilot generates its first post.') }}</p>
            @else
                <div>
                    <div class="ui-overline">{{ ___('This month by provider') }}</div>
                    <span class="ui-cell-tags">
                        @foreach($usage['by_provider'] as $p)
                            <x-ui.tag class="ui-num">{{ $p['provider'] }}: {{ number_format($p['calls']) }} {{ ___('calls') }} · {{ number_format($p['tokens']) }} {{ ___('tokens') }}</x-ui.tag>
                        @endforeach
                    </span>
                </div>
            @endif

            @if($usage['rate'] === null)
                <p class="ui-small ui-text-2">{{ ___('Set BLOG_AUTOPILOT_COST_PER_1K to show an estimated spend. ai_usage records tokens, not billed cost.') }}</p>
            @else
                <p class="ui-small ui-text-2">{{ ___('Estimated at') }} ${{ number_format($usage['rate'], 2) }} / 1K {{ ___('tokens — an estimate, not billed cost.') }}</p>
            @endif
        </div>
    </x-ui.card>

    {{-- ── B3: review strip — pending autopilot drafts, approval = existing blog edit ── --}}
    <x-ui.card :title="___('Awaiting review')" id="autopilot-review">
        <x-slot:actions><x-ui.tag tone="accent">{{ ___('This week') }}: {{ $publishedThisWeek }} / {{ $weeklyCap }}</x-ui.tag></x-slot:actions>
        <div class="ui-stack">
            @forelse($pendingReview as $draft)
                <div class="ui-cluster ui-cluster--between @unless($loop->first) ui-rule-top @endunless">
                    <div>
                        <div class="ui-strong">{{ text_shorting($draft->title, 70) }}</div>
                        <small class="ui-text-2">{{ date_formating($draft->created_at) }} · {{ $draft->locale ?: 'en' }}</small>
                    </div>
                    <x-ui.button variant="secondary" size="sm" :href="route('admin.blogs.edit', $draft->id)">{{ ___('Review & publish') }}</x-ui.button>
                </div>
            @empty
                <p class="ui-text-2">{{ ___('No drafts waiting for review.') }}</p>
            @endforelse
        </div>
    </x-ui.card>

    {{-- ── B3: topic backlog ── --}}
    <div class="ui-columns ui-columns--wide-first">
        <x-ui.card :title="___('Add a topic')">
            <form method="POST" action="{{ route('admin.blog.autopilot.topics.store') }}" class="ui-stack">
                @csrf
                <div class="ui-form-grid">
                    <x-ui.field name="title" id="topic_title" :label="___('Title').' *'" required maxlength="500" />
                    <x-ui.field name="keyword" id="topic_keyword" :label="___('Keyword')" maxlength="255" />
                    <x-ui.field name="angle" id="topic_angle" :label="___('Angle')" maxlength="500" />
                    <x-ui.field type="select" name="locale" id="topic_locale" :label="___('Language')"
                                :options="\Juwri\Core\Support\ViewHelpers::propOptions($languages, 'code', 'name')" />
                </div>
                <div class="ui-cluster ui-cluster--end">
                    <x-ui.button type="submit" size="sm" icon="plus">{{ ___('Add to queue') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
        <x-ui.card :title="___('Generate ideas')">
            <form method="POST" action="{{ route('admin.blog.autopilot.topics.ideas') }}" class="ui-stack">
                @csrf
                <div class="ui-form-grid">
                    <x-ui.field type="number" name="count" id="ideas_count" :label="___('How many')" min="1" max="10" value="5" />
                    <x-ui.field type="select" name="locale" id="ideas_locale" :label="___('Language')"
                                :options="\Juwri\Core\Support\ViewHelpers::propOptions($languages, 'code', 'name')" />
                </div>
                <p class="ui-small ui-text-2">{{ ___('Ideas are grounded in your product features and avoid duplicating existing posts.') }}</p>
                <div class="ui-cluster ui-cluster--end">
                    <x-ui.button type="submit" variant="secondary" size="sm" icon="sparkle" data-ui-busy>{{ ___('Generate ideas') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>

    <x-ui.card variant="flush" :title="___('Topic queue')" id="autopilot-topics">
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead>
                    <tr>
                        <th class="ui-dt__narrow">#</th>
                        <th>{{ ___('Title') }}</th>
                        <th>{{ ___('Keyword') }}</th>
                        <th>{{ ___('Language') }}</th>
                        <th>{{ ___('Source') }}</th>
                        <th>{{ ___('Status') }}</th>
                        <th>{{ ___('Created') }}</th>
                        <th class="ui-end">{{ ___('Action') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($topics as $topic)
                        <tr>
                            <td data-label="#" class="ui-text-2">{{ $topic->id }}</td>
                            <td data-primary data-label="{{ ___('Title') }}">
                                {{ text_shorting($topic->title, 50) }}
                                @if($topic->status === 'failed' && $topic->error)
                                    <small class="ui-cell-sub ui-text-danger">{{ text_shorting($topic->error, 90) }}</small>
                                @endif
                            </td>
                            <td data-label="{{ ___('Keyword') }}">{{ $topic->keyword ?: '—' }}</td>
                            <td data-label="{{ ___('Language') }}">{{ $topic->locale }}</td>
                            <td data-label="{{ ___('Source') }}"><x-ui.tag>{{ $topic->source }}</x-ui.tag></td>
                            <td data-label="{{ ___('Status') }}">
                                @switch($topic->status)
                                    @case('queued') <x-ui.tag>{{ ___('Queued') }}</x-ui.tag> @break
                                    @case('drafting') <x-ui.tag tone="accent">{{ ___('Drafting') }}</x-ui.tag> @break
                                    @case('drafted') <x-ui.tag tone="accent">{{ ___('Drafted') }}</x-ui.tag> @break
                                    @case('published') <x-ui.tag tone="success">{{ ___('Published') }}</x-ui.tag> @break
                                    @case('rejected') <x-ui.tag>{{ ___('Rejected') }}</x-ui.tag> @break
                                    @default <x-ui.tag tone="danger">{{ ___('Failed') }}</x-ui.tag>
                                @endswitch
                            </td>
                            <td data-label="{{ ___('Created') }}" class="ui-text-2">{{ date_formating($topic->created_at) }}</td>
                            <td class="ui-end" data-label="">
                                <div class="ui-cell-actions">
                                    @if(in_array($topic->status, ['queued', 'failed'], true))
                                        <form method="POST" action="{{ route('admin.blog.autopilot.topics.draft', $topic->id) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" title="{{ ___('Draft now') }}" data-ui-busy>{{ ___('Draft now') }}</x-ui.button>
                                        </form>
                                    @endif
                                    @if($topic->status === 'failed')
                                        <form method="POST" action="{{ route('admin.blog.autopilot.topics.retry', $topic->id) }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="secondary" size="sm" icon="refresh">{{ ___('Retry') }}</x-ui.button>
                                        </form>
                                    @endif
                                    @if($topic->status === 'drafted' && $topic->blog_id)
                                        <x-ui.button variant="secondary" size="sm" :href="route('admin.blogs.edit', $topic->blog_id)">{{ ___('Review') }}</x-ui.button>
                                    @endif
                                    @if(! in_array($topic->status, ['published', 'rejected'], true))
                                        <form method="POST" action="{{ route('admin.blog.autopilot.topics.reject', $topic->id) }}">
                                            @csrf
                                            <x-ui.button type="submit" variant="secondary" size="sm" class="ui-text-danger">{{ ___('Reject') }}</x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="ui-center ui-text-2">{{ ___('No topics yet — add one or generate ideas.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
@endsection

@push('scripts_at_top')
    {{-- Keeps the csv hidden field in step with the multi-select (the Option
         path stores blog_autopilot_locales as a csv string). --}}
    <script type="text/javascript" nonce="{{ csp_nonce() }}">
        "use strict";
        document.addEventListener('DOMContentLoaded', function () {
            var sel = document.getElementById('blog_autopilot_locales_select');
            var hidden = document.getElementById('blog_autopilot_locales');
            if (sel && hidden) {
                sel.addEventListener('change', function () {
                    var picked = [];
                    for (var i = 0; i < sel.options.length; i++) {
                        if (sel.options[i].selected) { picked.push(sel.options[i].value); }
                    }
                    hidden.value = picked.join(',');
                });
            }
        });
    </script>
@endpush
