{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P1 query-count probe: touches the shared globals so the wildcard composers fire. --}}
{{ isset($settings) ? 'settings-ok' : 'no-settings' }}|{{ isset($languages) ? 'languages-ok' : 'no-languages' }}
