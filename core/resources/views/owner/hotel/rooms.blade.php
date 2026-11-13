{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Hotel rooms'))
@section('page_title', ___('Rooms & areas'))
@section('page_sub', $store->title.' - '.___('Rooms, suites and zones with per-room QR codes.'))

@section('page_actions')
    <div class="ui-pop-host ui-menu-more" data-ui-pop>
        <button type="button" class="ui-btn ui-btn--secondary" data-ui-pop-toggle aria-haspopup="true" aria-expanded="false">
            <span class="ui-btn__icon"><x-ui.icon name="dots" /></span>{{ ___('More') }}
        </button>
        <div class="ui-pop ui-pop--end" data-ui-pop-panel hidden>
            <a class="ui-pop__item" href="{{ route('hotel.rooms.print') }}" target="_blank" rel="noopener"><x-ui.icon name="print" size="18" /> {{ ___('Print QR sheet') }}</a>
            @if(Route::has('hotel.pms.rooms.assign-type') && collect($roomTypes ?? [])->isNotEmpty())
                <button type="button" class="ui-pop__item" x-data @click="$dispatch('room-types-assign')"><x-ui.icon name="bed" size="18" /> {{ ___('Assign types') }}</button>
            @endif
            <button type="button" class="ui-pop__item" x-data @click="$dispatch('room-bulk')"><x-ui.icon name="more" size="18" /> {{ ___('Bulk create') }}</button>
        </div>
    </div>
    <button type="button" class="ui-btn" x-data @click="$dispatch('room-add')" data-controller="bridge--button" data-bridge-title="{{ ___('Add room') }}" data-bridge-ios-image="plus" data-bridge-android-image="add">
        <span class="ui-btn__icon"><x-ui.icon name="plus" /></span>{{ ___('Add room') }}
    </button>
@endsection

@php
    // HPMS-B7: cleanliness chips. hk_status is an additive lane-A column
    // (nullable pre-migration → chip simply hidden); the room-type name comes
    // via the roomType() relation post-merge, or a $room->room_type_name the
    // controller may pass. Both reads are null-safe pre-merge.
    $hkStyles = ['dirty' => 'warning', 'clean' => 'success', 'inspected' => 'accent'];
    $hkLabels = ['dirty' => ___('Needs cleaning'), 'clean' => ___('Clean'), 'inspected' => ___('Inspected')];

    // HPMS-B9: room-type assignment. $roomTypes is supplied by the rooms controller
    // once A11 extends the rooms flow; the bulk helper posts to A11's assignment
    // endpoint (Route::has-guarded → hidden pre-merge).
    $roomTypes = collect($roomTypes ?? []);
    $canBulkType = Route::has('hotel.pms.rooms.assign-type') && $roomTypes->isNotEmpty();
@endphp

@section('content')
<div class="ui-scope ui-rooms" x-data="hotelRoomsPage()" @room-add.window="openAdd()" @room-bulk.window="openBulk()" @room-types-assign.window="openTypes()">
    <section class="ui-card ui-card--flush">
        <div class="ui-card__head ui-rooms__head">
            <h2 class="ui-card__title">{{ ___('All rooms & areas') }}</h2>
            {{-- HP-B2: plain GET filter (CSP-clean, no JS) — search + floor + type, server-paginated. --}}
            <form method="get" action="{{ route('hotel.rooms') }}" class="ui-rooms__filters">
                <label class="ui-orders-search__field ui-rooms__search">
                    <x-ui.icon name="search" size="18" />
                    <span class="ui-visually-hidden">{{ ___('Search number or label') }}</span>
                    <input type="search" name="q" value="{{ $q }}" placeholder="{{ ___('Search number or label') }}">
                </label>
                @if($floors->isNotEmpty())
                    <select name="floor" class="ui-input ui-input--sm" aria-label="{{ ___('Floor / wing') }}">
                        <option value="">{{ ___('All floors') }}</option>
                        @foreach($floors as $f)
                            <option value="{{ $f }}" @selected($floor === $f)>{{ $f }}</option>
                        @endforeach
                    </select>
                @endif
                <select name="type" class="ui-input ui-input--sm" aria-label="{{ ___('Type') }}">
                    <option value="">{{ ___('All types') }}</option>
                    <option value="room" @selected($type === 'room')>{{ ___('Room') }}</option>
                    <option value="zone" @selected($type === 'zone')>{{ ___('Zone / area') }}</option>
                </select>
                <x-ui.button type="submit" variant="secondary" size="sm">{{ ___('Filter') }}</x-ui.button>
            </form>
        </div>
        @if($rooms->isEmpty())
            <div>
                @if($q !== '' || $floor !== '' || in_array($type, ['room','zone'], true))
                    <x-ui.empty :title="___('No matches')" :body="___('No rooms or areas match your search.')" icon="search" />
                @else
                    <x-ui.empty :title="___('No rooms yet')" :body="___('Add rooms or use bulk create (e.g. rooms 101–120) to generate a QR code for each one.')" icon="bed" />
                @endif
            </div>
        @else
            {{-- Owner UI kit table: rows become one card per room on phones. --}}
            <div>
                <table class="ui-table rooms-table">
                    <thead><tr>
                        <th>{{ ___('Number') }}</th>
                        <th>{{ ___('Label') }}</th>
                        <th>{{ ___('Type') }}</th>
                        <th>{{ ___('Floor / wing') }}</th>
                        <th class="ui-table__num">{{ ___('Actions') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($rooms as $room)
                            <tr>
                                <td class="room-no" data-primary><span class="ui-ltr">#{{ $room->table_no }}</span></td>
                                <td data-label="{{ ___('Label') }}">{{ $room->label ?: '—' }}</td>
                                <td class="room-type" data-label="{{ ___('Type') }}">
                                    <span class="ui-rooms__tags">
                                    <x-ui.tag>{{ ___(ucfirst((string) $room->area_type)) }}</x-ui.tag>
                                    @php $rtName = ($room instanceof \App\Models\StoreTable && $room->roomType) ? $room->roomType->name : ($room->room_type_name ?? null); @endphp
                                    @if($rtName)<x-ui.tag tone="accent">{{ $rtName }}</x-ui.tag>@endif
                                    @php $hk = $room->hk_status ?? null; @endphp
                                    @if($hk && isset($hkStyles[$hk]))<x-ui.pill :tone="$hkStyles[$hk]">{{ $hkLabels[$hk] }}</x-ui.pill>@endif
                                    </span>
                                </td>
                                <td data-label="{{ ___('Floor / wing') }}">{{ $room->floor_label ?: '—' }}</td>
                                <td class="room-actions">
                                    <div class="ui-rooms__actions">
                                        <a class="ui-icon-btn" href="{{ route('stores.qrDesigner', $store->id).'?table='.$room->key }}" aria-label="{{ ___('QR') }}" title="{{ ___('QR') }}"><x-ui.icon name="qr" /></a>
                                        <button type="button" class="ui-icon-btn" data-copy-link="{{ store_public_qr_url($store, $room->key) }}" aria-label="{{ ___('Copy link') }}" title="{{ ___('Copy link') }}"><x-ui.icon name="share" /></button>
                                        @if(Route::has('qr.designer.download') && \App\Services\FeatureAccess::storeAllows($store->id, 'qr_designer'))
                                            {{-- PARITY-RUN T10: a room QR is a table QR — same branded render, same rotation. --}}
                                            <a class="ui-icon-btn" href="{{ route('qr.designer.download').'?store_id='.$store->id.'&target=table&id='.$room->id.'&format=png' }}" aria-label="PNG" title="PNG"><x-ui.icon name="download" /></a>
                                            <form action="{{ route('qr.designer.rotate') }}?store_id={{ $store->id }}" method="post" data-confirm="{{ ___('Regenerate this QR? The printed code stops working.') }}">
                                                @csrf
                                                <input type="hidden" name="table_id" value="{{ $room->id }}">
                                                <button type="submit" class="ui-icon-btn" aria-label="{{ ___('Regenerate') }}" title="{{ ___('Regenerate') }}"><x-ui.icon name="refresh" /></button>
                                            </form>
                                        @endif
                                        {{-- HP-B2: @js() never compiles inside a component-TAG attribute; pass one
                                             Js::from object (the sibling menu/image blade does the same) so Edit opens. --}}
                                        <button type="button" class="ui-icon-btn" aria-label="{{ ___('Edit') }}" title="{{ ___('Edit') }}"
                                            @click="openEdit({{ \Illuminate\Support\Js::from(['id' => $room->id, 'no' => $room->table_no, 'type' => $room->area_type, 'label' => $room->label, 'floor' => $room->floor_label, 'room_type' => $room->room_type_id ?? null]) }})"><x-ui.icon name="pencil" /></button>
                                        <form action="{{ route('hotel.rooms.delete') }}" method="post" data-confirm="{{ ___('Delete this room?') }}" data-confirm-danger>
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $room->id }}">
                                            <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($rooms->hasPages())
                <div class="ui-catalog__pager">{{ $rooms->withQueryString()->links() }}</div>
            @endif
        @endif
    </section>

    {{-- Add / edit single room --}}
    <dialog class="ui-dialog ui-dialog--flat" x-ref="dlg" style="max-width:460px;">
        <form action="{{ route('hotel.rooms.store') }}" method="post">
            @csrf
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <h5 x-text="editId ? @js(___('Edit room')) : @js(___('Add room'))">{{ ___('Add room') }}</h5>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.dlg.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-stack">
                <input type="hidden" name="id" x-model="editId">
                <div>
                    <label class="ui-label">{{ ___('Number') }}</label>
                    <input type="number" name="table_no" class="ui-input" x-model="tableNo" required min="1">
                </div>
                <div>
                    <label class="ui-label">{{ ___('Label') }}</label>
                    <input type="text" name="label" class="ui-input" x-model="label" placeholder="{{ ___('e.g. Suite 4B') }}">
                </div>
                <div>
                    <label class="ui-label">{{ ___('Type') }}</label>
                    <select name="area_type" class="ui-input" x-model="areaType">
                        <option value="room">{{ ___('Room') }}</option>
                        <option value="zone">{{ ___('Zone / area') }}</option>
                    </select>
                </div>
                @if($roomTypes->isNotEmpty())
                {{-- HPMS-B9: room-type assignment (nullable). A11 extends the rooms
                     flow to persist room_type_id; pre-merge the field is simply
                     ignored by the store controller. --}}
                <div x-show="areaType === 'room'" x-cloak>
                    <label class="ui-label">{{ ___('Room type') }}</label>
                    <select name="room_type_id" class="ui-input" x-model="roomTypeId">
                        <option value="">{{ ___('— none —') }}</option>
                        @foreach($roomTypes as $rt)
                            <option value="{{ $rt->id }}">{{ $rt->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <div>
                    <label class="ui-label">{{ ___('Floor / wing') }}</label>
                    <input type="text" name="floor_label" class="ui-input" x-model="floorLabel" placeholder="{{ ___('e.g. Floor 1') }}">
                </div>
            </div>
            <div class="ui-cluster ui-cluster--end ui-rule-top">
                <x-ui.button type="button" variant="ghost" @click="$refs.dlg.close()">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ ___('Save') }}</x-ui.button>
            </div>
        </form>
    </dialog>

    {{-- Bulk create --}}
    <dialog class="ui-dialog ui-dialog--flat" x-ref="bulk" style="max-width:460px;">
        <form action="{{ route('hotel.rooms.bulk') }}" method="post">
            @csrf
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <h3 class="ui-section-title">{{ ___('Bulk create rooms') }}</h3>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.bulk.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-stack">
                <div class="ui-form-grid">
                    <div><label class="ui-label">{{ ___('From') }}</label><input type="number" name="from" class="ui-input" value="101" required min="1"></div>
                    <div><label class="ui-label">{{ ___('To') }}</label><input type="number" name="to" class="ui-input" value="120" required min="1"></div>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Type') }}</label>
                    <select name="area_type" class="ui-input"><option value="room">{{ ___('Room') }}</option><option value="zone">{{ ___('Zone / area') }}</option></select>
                </div>
                <div><label class="ui-label">{{ ___('Label prefix') }}</label><input type="text" name="prefix" class="ui-input" placeholder="{{ ___('e.g. Room') }}"></div>
                <div><label class="ui-label">{{ ___('Floor / wing') }}</label><input type="text" name="floor_label" class="ui-input" placeholder="{{ ___('e.g. Floor 1') }}"></div>
                <p class="ui-muted">{{ ___('Existing numbers are skipped. Max 500 at once.') }}</p>
            </div>
            <div class="ui-cluster ui-cluster--end ui-rule-top">
                <x-ui.button type="button" variant="ghost" @click="$refs.bulk.close()">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ ___('Create') }}</x-ui.button>
            </div>
        </form>
    </dialog>

    {{-- HPMS-B9: bulk-assign a room type by floor / area type (A11 endpoint). --}}
    @if($canBulkType)
    <dialog class="ui-dialog ui-dialog--flat" x-ref="types" style="max-width:460px;">
        <form action="{{ route('hotel.pms.rooms.assign-type') }}" method="post">
            @csrf
            <div class="ui-cluster ui-cluster--between ui-rule-bottom">
                <h3 class="ui-section-title">{{ ___('Assign room type') }}</h3>
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" @click="$refs.types.close()" aria-label="{{ ___('Close') }}"><x-ui.icon name="x" size="18" /></button>
            </div>
            <div class="ui-stack">
                <div>
                    <label class="ui-label">{{ ___('Room type') }}</label>
                    <select name="room_type_id" class="ui-input" required>
                        <option value="">{{ ___('— none —') }}</option>
                        @foreach($roomTypes as $rt)
                            <option value="{{ $rt->id }}">{{ $rt->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Apply to floor / wing') }}</label>
                    <select name="floor" class="ui-input">
                        <option value="">{{ ___('All floors') }}</option>
                        @foreach($floors as $f)
                            <option value="{{ $f }}">{{ $f }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="ui-label">{{ ___('Area type') }}</label>
                    <select name="area_type" class="ui-input">
                        <option value="room">{{ ___('Rooms only') }}</option>
                        <option value="">{{ ___('Rooms and zones') }}</option>
                    </select>
                </div>
                <p class="ui-muted">{{ ___('Every matching room is set to this type. Leave the floor empty to assign across the whole property.') }}</p>
            </div>
            <div class="ui-cluster ui-cluster--end ui-rule-top">
                <x-ui.button type="button" variant="ghost" @click="$refs.types.close()">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check">{{ ___('Assign') }}</x-ui.button>
            </div>
        </form>
    </dialog>
    @endif
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
    function hotelRoomsPage() {
        return {
            editId: '', tableNo: '', label: '', areaType: 'room', floorLabel: '', roomTypeId: '',
            openAdd() { this.editId=''; this.tableNo=''; this.label=''; this.areaType='room'; this.floorLabel=''; this.roomTypeId=''; this.$refs.dlg.showModal(); },
            openEdit(o) { this.editId=o.id; this.tableNo=o.no; this.areaType=o.type||'room'; this.label=o.label||''; this.floorLabel=o.floor||''; this.roomTypeId=(o.room_type!=null?String(o.room_type):''); this.$refs.dlg.showModal(); },
            openBulk() { this.$refs.bulk.showModal(); },
            openTypes() { if (this.$refs.types) { this.$refs.types.showModal(); } },
        };
    }
    document.querySelectorAll('[data-copy-link]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            navigator.clipboard.writeText(btn.dataset.copyLink).then(function () {
                if (window.ownerUi) { window.ownerUi.toast(@js(___('Copied'))); }
                else { (btn.querySelector('.btn__label') || btn).textContent = @js(___('Copied')); }
            });
        });
    });
</script>
@endpush
