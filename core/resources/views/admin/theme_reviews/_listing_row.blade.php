<tr class="listing-row" data-id="{{ $t->id }}" draggable="true">
    <td>
        <span class="quick-reorder-icon" title="{{ ___('Reorder') }}"><x-ui.icon name="menu" size="16" /></span>
    </td>
    <td data-primary data-label="{{ ___('Theme') }}"><span class="ui-strong">{{ $t->name }}</span> <code class="ui-mono ui-small">{{ $t->slug }}</code></td>
    <td data-label="{{ ___('Position') }}" data-pos>{{ $t->sort_order ?? '—' }}</td>
    <td data-label="{{ ___('Category') }}">
        @if($t->category)
            <x-ui.tag tone="accent">{{ ___($t->category) }}</x-ui.tag>
        @else
            <x-ui.tag tone="warning">{{ ___('uncategorized') }}</x-ui.tag>
        @endif
    </td>
    <td data-label="{{ ___('Tagline') }}" class="ui-text-2">{{ Str::limit($t->tagline, 60) ?: '—' }}</td>
    <td data-label="{{ ___('Screenshots') }}">{{ $t->screenshots_count }}</td>
    <td class="ui-end" data-label="">
        <x-ui.button variant="secondary" size="sm" icon="pencil" :href="route('admin.theme-reviews.listing', $t->id)">{{ ___('Edit listing') }}</x-ui.button>
    </td>
</tr>
