<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Database\Seeders;

use Juwri\Modules\Hotel\Models\HotelStay;

/**
 * One checked-in guest stay per room - the rule the hotel demo seeders share.
 *
 * A room is a `store_tables` row; its guest stay is a `hotel_stays` row. The
 * product charges the NEWEST checked-in stay of the room (that stay's folio),
 * while the guest's own link names the stay the credentials file points at.
 * A second row on the same room therefore splits the folio in two: the guest
 * opens an empty folio while the charge sits on the other row. So a room
 * carries exactly ONE stay, keyed on the stay identity (restaurant + room) and
 * never on the token.
 *
 * Written in place, not deleted and re-created: the row that carries the folio
 * keeps the charges and a leftover row without one is dropped. That heals a
 * demo seeded by an older build - the theme showcase seeder used to key its
 * stay by token, so the demo kitchen's room 101 held two stays.
 *
 * @see \Juwri\Modules\Hotel\Database\Seeders\HotelDemoSeeder
 * @see \Juwri\Modules\Hotel\Database\Seeders\HotelShowcaseSeeder
 */
final class HotelStayRows
{
    /**
     * The one checked-in stay of a room, written in place.
     *
     * @param  array<string, mixed>  $attributes  token, guest_name, guest_phone, checked_in_at, ...
     */
    public static function onePerRoom(int $storeId, int $roomId, array $attributes): HotelStay
    {
        $rows = HotelStay::where('store_id', $storeId)
            ->where('table_id', $roomId)
            ->orderBy('id')
            ->get();

        // The row that carries the folio keeps the charges; the oldest row is the
        // fallback when no row carries one yet.
        $stay = $rows->firstWhere(fn (HotelStay $row): bool => $row->folio_account_id !== null)
            ?? $rows->first();

        foreach ($rows as $row) {
            if ($stay instanceof HotelStay && (int) $row->id === (int) $stay->id) {
                continue;
            }
            if ($row->folio_account_id !== null) {
                // a row that holds charges is never dropped, but it is no longer the room's checked-in stay
                if ($row->status === 'checked_in') {
                    $row->forceFill(['status' => 'checked_out', 'checked_out_at' => now()])->save();
                }

                continue;
            }

            $row->delete();
        }

        if (! $stay instanceof HotelStay) {
            $stay = new HotelStay(['store_id' => $storeId, 'table_id' => $roomId]);
        }

        $stay->fill(array_merge($attributes, [
            'store_id' => $storeId,
            'table_id' => $roomId,
            'status' => 'checked_in',
        ]))->save();

        return $stay;
    }
}
