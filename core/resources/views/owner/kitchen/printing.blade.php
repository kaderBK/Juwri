{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Printing'))
@section('page_title', ___('Printing'))
@section('page_sub', ___('Send receipts and kitchen tickets straight to a printer.'))

@section('page_actions')
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')

@if(session('success'))
    <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
@endif
@if(isset($errors) && $errors->any())
    <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
@endif

@php
    $transportLabels = [
        'browser'   => ___('Browser print dialog'),
        'webserial' => ___('USB / serial (this browser)'),
        'webusb'    => ___('USB (WebUSB)'),
        'rawbt'     => ___('Android (RawBT)'),
        'bridge'    => ___('Network printer (desktop app)'),
        'sunmi'     => ___('Sunmi kiosk (built-in)'),
    ];
    $eventLabels = [
        'new_order'   => ___('New order'),
        'paid'        => ___('Paid'),
        'kot'         => ___('Kitchen ticket'),
        'shift_close' => ___('Shift report'),
    ];
@endphp

<div class="ui-scope ui-print">
    <div class="ui-stack ui-print__main">

        {{-- ── Printers ───────────────────────────────────────────────────── --}}
        <x-ui.card :title="___('Printers')">
            <p class="ui-print__muted">{{ ___('Each printer decides what it prints, at which branch, for which kitchen stations.') }}</p>

            @forelse($profiles as $p)
                <div class="ui-print__printer">
                    {{-- D3: last-ack health chip for this printer. --}}
                    @php $last = ($lastByProfile ?? [])[$p->id] ?? null; @endphp
                    <div class="ui-cluster ui-print__health">
                        <strong>{{ $p->name }}</strong>
                        @if($last)
                            <x-ui.tag :tone="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::printJobTone($last->status)">{{ $last->status }}</x-ui.tag>
                            @if($last->error)<small class="ui-print__error">{{ $last->error }}</small>@endif
                            <small class="ui-print__muted">{{ ___('last') }}: {{ optional($last->completed_at ?? $last->created_at)->format('Y-m-d H:i') }}</small>
                        @else
                            <x-ui.tag>{{ ___('no jobs yet') }}</x-ui.tag>
                        @endif
                    </div>
                    <form method="post" action="{{ route('stores.printing.update', $p->id) }}?store_id={{ $rid }}" class="ui-print__form">
                        @csrf
                        <div class="ui-print__f">
                            <label class="ui-label" for="pn-{{ $p->id }}">{{ ___('Name') }}</label>
                            <input id="pn-{{ $p->id }}" name="name" class="ui-input" value="{{ $p->name }}" required maxlength="80">
                        </div>
                        <div class="ui-print__f">
                            <label class="ui-label" for="pt-{{ $p->id }}">{{ ___('Connection') }}</label>
                            <select id="pt-{{ $p->id }}" name="transport" class="ui-input">
                                @foreach($transports as $t)
                                    @continue($t === 'sunmi')
                                    <option value="{{ $t }}" @selected($p->transport === $t)>{{ $transportLabels[$t] ?? $t }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="ui-print__f">
                            <label class="ui-label" for="pw-{{ $p->id }}">{{ ___('Paper width') }}</label>
                            <select id="pw-{{ $p->id }}" name="width_mm" class="ui-input">
                                <option value="80" @selected((int) $p->width_mm === 80)>80mm (48 {{ ___('columns') }})</option>
                                <option value="57" @selected((int) $p->width_mm === 57)>57mm (32 {{ ___('columns') }})</option>
                            </select>
                        </div>
                        <div class="ui-print__f ui-print__f--wide">
                            <label class="ui-label" for="pa-{{ $p->id }}">{{ ___('Printer address') }}</label>
                            <input id="pa-{{ $p->id }}" name="target" class="ui-input ui-ltr" value="{{ $p->target }}" maxlength="190" placeholder="tcp://192.168.1.50:9100">
                            <small class="ui-field__help">{{ ___('Network printers use tcp://ip:9100. Leave empty for USB and browser printing.') }}</small>
                        </div>
                        <div class="ui-print__f ui-print__f--wide">
                            <label class="ui-label" for="pb-{{ $p->id }}">{{ ___('Desktop app address') }}</label>
                            <input id="pb-{{ $p->id }}" name="bridge_url" class="ui-input ui-ltr" value="{{ $p->bridge_url }}" maxlength="190" placeholder="{{ \Juwri\Modules\Pos\Models\PrinterProfile::DEFAULT_BRIDGE_URL }}">
                        </div>
                        <div class="ui-print__f">
                            <label class="ui-label" for="pbr-{{ $p->id }}">{{ ___('Branch') }}</label>
                            <select id="pbr-{{ $p->id }}" name="branch_id" class="ui-input">
                                <option value="">{{ ___('All branches') }}</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" @selected((int) $p->branch_id === (int) $b->id)>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="ui-print__f">
                            <label class="ui-label" for="pc-{{ $p->id }}">{{ ___('Copies') }}</label>
                            <input id="pc-{{ $p->id }}" type="number" name="copies" class="ui-input" min="1" max="5" value="{{ (int) $p->copies }}">
                        </div>
                        {{-- D3: fall back to another printer when this one fails. --}}
                        <div class="ui-print__f">
                            <label class="ui-label" for="pf-{{ $p->id }}">{{ ___('If this printer fails, use') }}</label>
                            <select id="pf-{{ $p->id }}" name="fallback_profile_id" class="ui-input">
                                <option value="">{{ ___('No fallback') }}</option>
                                @foreach($profiles as $fb)
                                    @if((int) $fb->id !== (int) $p->id)
                                        <option value="{{ $fb->id }}" @selected((int) $p->fallback_profile_id === (int) $fb->id)>{{ $fb->name }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="ui-print__f">
                            <label class="ui-label" for="ps-{{ $p->id }}">{{ ___('Kitchen stations') }}</label>
                            <select id="ps-{{ $p->id }}" name="station_ids[]" class="ui-input" multiple size="3">
                                @forelse($stations as $st)
                                    <option value="{{ $st->id }}" @selected(in_array((int) $st->id, $p->stationFilter(), true))>{{ $st->name }}</option>
                                @empty
                                    <option disabled>{{ ___('No stations yet') }}</option>
                                @endforelse
                            </select>
                            <small class="ui-field__help">{{ ___('Empty = every item on one ticket.') }}</small>
                        </div>
                        <fieldset class="ui-print__f ui-print__f--full ui-print__events">
                            <legend class="ui-label">{{ ___('Prints') }}</legend>
                            <div class="ui-cluster">
                                @foreach($events as $ev)
                                    <x-ui.check name="events[]" :value="$ev" :label="$eventLabels[$ev] ?? $ev" :checked="$p->handlesEvent($ev)" id="ev-{{ $p->id }}-{{ $ev }}" />
                                @endforeach
                            </div>
                        </fieldset>
                        <div class="ui-print__f ui-print__f--wide ui-print__switches">
                            <x-ui.switch name="auto_print" :checked="$p->auto_print" :label="___('Print automatically')" :id="'auto-'.($p->id)" />
                            <x-ui.switch name="active" :checked="$p->active" :label="___('Enabled')" :id="'act-'.($p->id)" />
                        </div>
                        <div class="ui-print__f ui-print__f--wide ui-print__submit">
                            <x-ui.button type="submit" size="sm" data-ui-busy>{{ ___('Save') }}</x-ui.button>
                        </div>
                    </form>
                    <div class="ui-cluster ui-print__row-actions">
                        <form method="post" action="{{ route('stores.printing.test', $p->id) }}?store_id={{ $rid }}">@csrf
                            <x-ui.button type="submit" size="sm" variant="secondary" icon="print">{{ ___('Test print') }}</x-ui.button>
                        </form>
                        <form method="post" action="{{ route('stores.printing.destroy', $p->id) }}?store_id={{ $rid }}"
                              data-confirm="{{ ___('Remove this printer?') }}" data-confirm-danger>@csrf @method('DELETE')
                            <x-ui.button type="submit" size="sm" variant="danger" icon="trash">{{ ___('Delete') }}</x-ui.button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="ui-print__muted">{{ ___('No printers yet — add your first one below.') }}</p>
            @endforelse

            {{-- The Sunmi kiosk printer already existed and is configured on the Kiosk
                 page; showing it here read-only stops an owner from adding a second,
                 conflicting profile for the same physical head. --}}
            @if(\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'kiosk_auto_print') == '1')
                <div class="ui-print__printer ui-print__printer--inset ui-cluster">
                    <div class="ui-print__grow">
                        <p class="ui-print__name">{{ ___('Sunmi kiosk (built-in)') }}
                            <x-ui.tag>{{ ___('Read only') }}</x-ui.tag>
                        </p>
                        <p class="ui-print__muted">
                            {{ ___('Paid') }} · {{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'kiosk_printer_width') == 32 ? '57mm' : '80mm' }}
                        </p>
                    </div>
                    @if(Route::has('stores.kiosk.index'))
                        <x-ui.button size="sm" variant="secondary" :href="route('stores.kiosk.index').'?store_id='.$rid">{{ ___('Kiosk') }}</x-ui.button>
                    @endif
                </div>
            @endif
        </x-ui.card>

        {{-- ── Add ────────────────────────────────────────────────────────── --}}
        <x-ui.card :title="___('Add a printer')">
            <form method="post" action="{{ route('stores.printing.store') }}?store_id={{ $rid }}" class="ui-print__form">
                @csrf
                <div class="ui-print__f">
                    <label class="ui-label" for="new-name">{{ ___('Name') }}</label>
                    <input id="new-name" name="name" class="ui-input" required maxlength="80" placeholder="{{ ___('e.g. Kitchen printer') }}">
                </div>
                <div class="ui-print__f">
                    <label class="ui-label" for="new-transport">{{ ___('Connection') }}</label>
                    <select id="new-transport" name="transport" class="ui-input">
                        @foreach($transports as $t)
                            @continue($t === 'sunmi')
                            <option value="{{ $t }}">{{ $transportLabels[$t] ?? $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ui-print__f">
                    <label class="ui-label" for="new-width">{{ ___('Paper width') }}</label>
                    <select id="new-width" name="width_mm" class="ui-input">
                        <option value="80">80mm</option>
                        <option value="57">57mm</option>
                    </select>
                </div>
                <div class="ui-print__f">
                    <label class="ui-label" for="new-copies">{{ ___('Copies') }}</label>
                    <input id="new-copies" type="number" name="copies" class="ui-input" min="1" max="5" value="1">
                </div>
                <div class="ui-print__f ui-print__f--wide">
                    <label class="ui-label" for="new-target">{{ ___('Printer address') }}</label>
                    <input id="new-target" name="target" class="ui-input ui-ltr" maxlength="190" placeholder="tcp://192.168.1.50:9100">
                </div>
                <div class="ui-print__f">
                    <label class="ui-label" for="new-branch">{{ ___('Branch') }}</label>
                    <select id="new-branch" name="branch_id" class="ui-input">
                        <option value="">{{ ___('All branches') }}</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- D3: optional fallback printer for a new profile. --}}
                <div class="ui-print__f">
                    <label class="ui-label" for="new-fallback">{{ ___('If this printer fails, use') }}</label>
                    <select id="new-fallback" name="fallback_profile_id" class="ui-input">
                        <option value="">{{ ___('No fallback') }}</option>
                        @foreach($profiles as $fb)
                            <option value="{{ $fb->id }}">{{ $fb->name }}</option>
                        @endforeach
                    </select>
                </div>
                <fieldset class="ui-print__f ui-print__f--full ui-print__events">
                    <legend class="ui-label">{{ ___('Prints') }}</legend>
                    <div class="ui-cluster">
                        @foreach($events as $ev)
                            <x-ui.check name="events[]" :value="$ev" :label="$eventLabels[$ev] ?? $ev" id="new-ev-{{ $ev }}" />
                        @endforeach
                    </div>
                </fieldset>
                <div class="ui-print__f ui-print__f--wide ui-print__switches">
                    <x-ui.switch name="auto_print" :checked="true" :label="___('Print automatically')" id="new-auto" />
                    <x-ui.switch name="active" :checked="true" :label="___('Enabled')" id="new-act" />
                </div>
                <div class="ui-print__f ui-print__f--wide ui-print__submit">
                    <x-ui.button type="submit" icon="plus" data-ui-busy>{{ ___('Add printer') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>

        {{-- ── Print-job history (D3: filterable + paginated) ─────────────── --}}
        <x-ui.card :title="___('Print-job history')">
            <form method="get" class="ui-print__filters">
                <input type="hidden" name="store_id" value="{{ $rid }}">
                <select name="job_profile" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Printer') }}">
                    <option value="0">{{ ___('All printers') }}</option>
                    @foreach($profiles as $fp)
                        <option value="{{ $fp->id }}" @selected((int) ($jobProfile ?? 0) === (int) $fp->id)>{{ $fp->name }}</option>
                    @endforeach
                </select>
                <select name="job_status" class="ui-input ui-input--sm" data-ui-do="submit" aria-label="{{ ___('Status') }}">
                    <option value="">{{ ___('All statuses') }}</option>
                    @foreach(\Juwri\Modules\Pos\Models\PrintJob::STATUSES as $st)
                        <option value="{{ $st }}" @selected(($jobStatus ?? '') === $st)>{{ $st }}</option>
                    @endforeach
                </select>
            </form>
            @if($recentJobs->isEmpty())
                <p class="ui-print__muted">{{ ___('Nothing has been printed yet.') }}</p>
            @else
                <table class="ui-table ui-print__jobs">
                    <thead><tr>
                        <th>#</th><th>{{ ___('Prints') }}</th><th>{{ ___('Printer') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Tries') }}</th><th>{{ ___('Time') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($recentJobs as $j)
                        <tr>
                            <td data-label="#" class="ui-ltr">{{ $j->order_id ? '#'.$j->order_id : '—' }}</td>
                            <td data-label="{{ ___('Prints') }}">{{ $eventLabels[$j->event] ?? $j->event }}</td>
                            <td data-label="{{ ___('Printer') }}">{{ optional($j->profile)->name ?? ($transportLabels[$j->transport] ?? $j->transport) }}
                                @if($j->fallback_of_job_id)<x-ui.tag tone="accent">{{ ___('fallback') }}</x-ui.tag>@endif
                            </td>
                            <td data-label="{{ ___('Status') }}">
                                <x-ui.tag :tone="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::printJobTone($j->status)">{{ $j->status }}</x-ui.tag>
                                @if($j->error)<small class="ui-print__error">{{ $j->error }}</small>@endif
                            </td>
                            <td data-label="{{ ___('Tries') }}" class="ui-print__muted ui-num">{{ (int) $j->attempts }}</td>
                            <td data-label="{{ ___('Time') }}" class="ui-print__muted ui-num">{{ optional($j->created_at)->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div class="ui-print__pager">{{ $recentJobs->links() }}</div>
            @endif
        </x-ui.card>
    </div>

    <div class="ui-stack ui-print__side">
        {{-- ── Store-wide options ─────────────────────────────────────────── --}}
        <x-ui.card :title="___('Receipt options')">
            <form method="post" action="{{ route('stores.printing.options') }}?store_id={{ $rid }}" class="ui-stack">
                @csrf
                {{-- Hidden 0 before each switch: an unticked box still saves "off". --}}
                <input type="hidden" name="print_logo" value="0">
                <x-ui.switch name="print_logo" id="print_logo" :checked="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'print_logo') == '1'" :label="___('Print the store logo')" />
                <input type="hidden" name="print_open_drawer" value="0">
                <x-ui.switch name="print_open_drawer" id="print_open_drawer" :checked="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'print_open_drawer') == '1'" :label="___('Kick cash drawer on payment')" />
                <input type="hidden" name="print_rtl" value="0">
                <x-ui.switch name="print_rtl" id="print_rtl" :checked="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'print_rtl') == '1'" :label="___('Right-to-left receipts')" :description="___('Shapes Arabic letters and mirrors the layout.')" />
                <div>
                    <label class="ui-label" for="print_rtl_font">{{ ___('Arabic font file') }}</label>
                    <input id="print_rtl_font" name="print_rtl_font" class="ui-input ui-ltr" value="{{ \Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'print_rtl_font') }}" maxlength="190" placeholder="/path/to/font.ttf">
                    <small class="ui-field__help">{{ ___('Optional. With a font file Arabic lines print as images, which every printer renders correctly.') }}</small>
                </div>
                <x-ui.button type="submit" :block="true" data-ui-busy>{{ ___('Save') }}</x-ui.button>
            </form>
        </x-ui.card>

        {{-- ── Desktop bridge pairing ─────────────────────────────────────── --}}
        <x-ui.card :title="___('Desktop app pairing')">
            <p class="ui-print__muted">{{ ___('Network printers need the desktop app running on the till. Paste this code into it once.') }}</p>
            @if($pairingToken)
                <input class="ui-input ui-input--sm ui-ltr ui-print__token" value="{{ $pairingToken }}" readonly data-ui-do="select" aria-label="{{ ___('Desktop app pairing') }}">
            @else
                <p class="ui-print__muted">{{ ___('No pairing code yet.') }}</p>
            @endif
            <form method="post" action="{{ route('stores.printing.token') }}?store_id={{ $rid }}"
                  data-confirm="{{ ___('Generate a new code? The desktop app will need pairing again.') }}">
                @csrf
                <x-ui.button type="submit" size="sm" variant="secondary" :block="true">
                    {{ $pairingToken ? ___('Generate a new code') : ___('Generate a pairing code') }}
                </x-ui.button>
            </form>
        </x-ui.card>

        {{-- ── Help ───────────────────────────────────────────────────────── --}}
        <x-ui.card variant="inset" :title="___('How each connection works')" heading="h3">
            <ul class="ui-print__help">
                <li>{{ ___('USB / serial: pair the printer once from the register, in Chrome or Edge.') }}</li>
                <li>{{ ___('Android: install RawBT on the tablet, then print over Bluetooth or USB.') }}
                    <a href="{{ $rawbtUrl }}" target="_blank" rel="noopener">{{ ___('Get RawBT') }}</a></li>
                <li>{{ ___('Network printers: run the desktop app on the till and pair it with the code above.') }}</li>
                <li>{{ ___('Browser print dialog: works everywhere, but you pick the printer each time.') }}</li>
            </ul>
        </x-ui.card>
    </div>
</div>

@endsection
