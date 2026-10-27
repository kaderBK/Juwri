{{-- P1-17: only the cards fed by the recorders that are on (slow requests/queries, exceptions, queues, cache). No server, usage, slow-job or outgoing-request cards. --}}
<x-pulse>
    <livewire:pulse.queues cols="4" />

    <livewire:pulse.cache cols="4" />

    <livewire:pulse.exceptions cols="4" />

    <livewire:pulse.slow-queries cols="6" />

    <livewire:pulse.slow-requests cols="6" />
</x-pulse>
