{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Shared sync/push log table.
    $log  (iterable)  rows with ->created_at, ->status, ->message (or ->detail)
    $title (string|null)
--}}
<div class="ui-card integration-sync-log"><div class="ui-card__body">
    <h3 class="ui-section-title">{{ $title ?? ___('Sync log') }}</h3>
    @if(empty($log) || (is_countable($log) && count($log) === 0) || (method_exists($log, 'isEmpty') && $log->isEmpty()))
        <p class="ui-text-2">{{ ___('No activity yet.') }}</p>
    @else
        <div class="ui-table-wrap">
            <table class="ui-table">
                <thead><tr>
                    <th>{{ ___('When') }}</th>
                    <th>{{ ___('Status') }}</th>
                    <th>{{ ___('Detail') }}</th>
                </tr></thead>
                <tbody>
                @foreach($log as $row)
                    @php
                        $status = $row->status ?? '';
                        $ok = in_array(strtolower((string) $status), ['ok', 'success', 'synced', 'pushed', 'done', 'accepted'], true);
                    @endphp
                    <tr>
                        <td class="ui-nowrap" data-label="{{ ___('When') }}">{{ optional($row->created_at)->diffForHumans() ?? ($row->created_at ?? '') }}</td>
                        <td data-label="{{ ___('Status') }}"><span class="ui-tag" data-tone="{{ $ok ? 'success' : 'warning' }}">{{ $status }}</span></td>
                        <td data-label="{{ ___('Detail') }}">{{ $row->message ?? ($row->detail ?? '') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div></div>
