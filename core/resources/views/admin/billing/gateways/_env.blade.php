{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Sandbox / live switch stored as 1 / 0. $name: option key, $help: optional line. --}}
<x-ui.field type="select" :name="$name" :label="$label ?? ___('Environment')" :value="(string) ($settings->{$name} ?? '')"
            :options="['1' => ___('Sandbox (test)'), '0' => ___('Live')]" :help="$help ?? null" />
