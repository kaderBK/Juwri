{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('admin.layout')
@section('title', ___('New A/B Experiment'))
@section('header_buttons')
    <x-ui.button variant="ghost" size="sm" icon="back" :href="route('admin.ab-experiments.index')">{{ ___('A/B Experiments') }}</x-ui.button>
@endsection
@section('content')
<x-ui.card :title="___('New Experiment')" style="max-inline-size: 720px">
    <div class="ui-stack">
        @if ($errors->any())
            <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
        @endif
        <form method="POST" action="{{ route('admin.ab-experiments.store') }}" class="ui-stack">
            @csrf
            <div class="ui-stack">
                <x-ui.field name="name" :label="___('Name').' ('.___('slug, no spaces').')'" placeholder="hero_cta" required
                            pattern="[a-z0-9_\-]+" title="Lowercase letters, numbers, underscores, dashes" error="" />
                <small class="ui-small ui-text-2">{{ ___('Used in code:') }} <code class="ui-mono">ab_variant('hero_cta')</code></small>
            </div>
            <x-ui.field name="description" :label="___('Description')" placeholder="Testing CTA button copy" error="" />
            <div class="ui-stack">
                <x-ui.field name="variants" :label="___('Variants').' ('.___('comma-separated').')'" placeholder="A,B" value="A,B" required error="" />
                <small class="ui-small ui-text-2">{{ ___('E.g.') }} <code class="ui-mono">A,B</code> {{ ___('or') }} <code class="ui-mono">control,variant1,variant2</code></small>
            </div>
            <div class="ui-cluster ui-cluster--end">
                <x-ui.button variant="ghost" :href="route('admin.ab-experiments.index')">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ ___('Create Experiment') }}</x-ui.button>
            </div>
        </form>
    </div>
</x-ui.card>
@endsection
