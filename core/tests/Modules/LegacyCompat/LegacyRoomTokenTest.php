<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Store;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacyRoomCharge;
use Juwri\Modules\LegacyCompat\Support\LegacyRoomTokenKeys;
use Tests\TestCase;

/**
 * J52b: printed hotel-room tokens minted under the OLD app key keep verifying on a migrated install (plan §5.3):
 * verify accepts the current key or an old key (stored encrypted), bound to one store and one room; mint always uses
 * the current key. Fake old keys only.
 */
class LegacyRoomTokenTest extends TestCase
{
    private const OLD_KEY = 'base64:c3ludGhldGljLW9sZC1hcHAta2V5LWZvci10ZXN0cw==';

    private function room(int $storeId, int $roomId): Model
    {
        /** @var Model $room */
        $room = (new Store)->tables()->getRelated()->newInstance();
        $room->forceFill(['id' => $roomId, 'store_id' => $storeId, 'area_type' => 'room']);

        return $room;
    }

    /** @param list<string>|null $encryptedKeys */
    private function turnOn(?array $encryptedKeys = null, ?string $until = '2099-01-01'): RoomCharge
    {
        config([
            'legacy_compat.enabled' => true,
            'legacy_compat.room_token_legacy_keys' => $encryptedKeys ?? [Crypt::encryptString(self::OLD_KEY)],
            'legacy_compat.room_token_legacy_until' => (string) $until,
        ]);
        $this->app->forgetInstance(LegacyRoomTokenKeys::class);
        LegacyCompatServiceProvider::activate($this->app);

        return app(RoomCharge::class);
    }

    public function test_off_by_default_the_binding_is_untouched_and_old_key_tokens_fail(): void
    {
        $charge = app(RoomCharge::class);

        $this->assertNotInstanceOf(LegacyRoomCharge::class, $charge);
        $this->assertFalse($charge->verifyToken($this->room(5, 11), LegacyRoomTokenKeys::sign(5, 11, self::OLD_KEY)));
    }

    public function test_on_a_token_from_either_key_verifies(): void
    {
        $charge = $this->turnOn();
        $room = $this->room(5, 11);

        $this->assertInstanceOf(LegacyRoomCharge::class, $charge);
        $this->assertTrue($charge->verifyToken($room, $charge->mintToken($room)), 'current-key token');
        $this->assertTrue($charge->verifyToken($room, LegacyRoomTokenKeys::sign(5, 11, self::OLD_KEY)), 'old-key token');
    }

    public function test_mint_always_uses_the_current_key(): void
    {
        $charge = $this->turnOn();
        $room = $this->room(5, 11);

        $minted = $charge->mintToken($room);
        $this->assertSame(LegacyRoomTokenKeys::sign(5, 11, (string) config('app.key')), $minted);
        $this->assertNotSame(LegacyRoomTokenKeys::sign(5, 11, self::OLD_KEY), $minted);
        $this->assertInstanceOf(LegacyRoomCharge::class, $charge);
        $this->assertSame($charge->inner()->mintToken($room), $minted);
    }

    /** Coordinator negative tests: another room, another store, an altered or unsigned token is refused. */
    public function test_tokens_for_another_room_or_store_or_altered_are_refused(): void
    {
        $charge = $this->turnOn();
        $room = $this->room(5, 11);
        $good = LegacyRoomTokenKeys::sign(5, 11, self::OLD_KEY);

        $this->assertFalse($charge->verifyToken($room, LegacyRoomTokenKeys::sign(5, 12, self::OLD_KEY)), 'other room');
        $this->assertFalse($charge->verifyToken($room, LegacyRoomTokenKeys::sign(6, 11, self::OLD_KEY)), 'other store');
        $this->assertFalse($charge->verifyToken($this->room(6, 11), $good), 'token replayed on another store');
        $this->assertFalse($charge->verifyToken($room, LegacyRoomTokenKeys::sign(5, 11, 'base64:some-other-key')), 'wrong key');

        $flipped = substr($good, 0, -1).($good[39] === '0' ? '1' : '0');
        foreach ([$flipped, strtoupper($good), substr($good, 0, 39), $good.'0', '', null, 'room|5|11', base64_encode('room|5|11')] as $bad) {
            $this->assertFalse($charge->verifyToken($room, $bad), var_export($bad, true));
        }
    }

    public function test_a_plaintext_or_undecryptable_key_entry_is_ignored(): void
    {
        $charge = $this->turnOn([self::OLD_KEY, 'not-encrypted', Crypt::encryptString('')]);

        $this->assertFalse($charge->verifyToken($this->room(5, 11), LegacyRoomTokenKeys::sign(5, 11, self::OLD_KEY)));
        $this->assertSame(0, app(LegacyRoomTokenKeys::class)->count());
    }

    public function test_several_old_keys_are_accepted(): void
    {
        $charge = $this->turnOn([Crypt::encryptString('base64:first-old'), Crypt::encryptString(self::OLD_KEY)]);

        $this->assertTrue($charge->verifyToken($this->room(5, 11), LegacyRoomTokenKeys::sign(5, 11, self::OLD_KEY)));
        $this->assertTrue($charge->verifyToken($this->room(5, 11), LegacyRoomTokenKeys::sign(5, 11, 'base64:first-old')));
    }

    public function test_switching_off_at_runtime_stops_old_key_tokens_but_not_current_ones(): void
    {
        $charge = $this->turnOn();
        $room = $this->room(5, 11);
        config(['legacy_compat.enabled' => false]);

        $this->assertFalse($charge->verifyToken($room, LegacyRoomTokenKeys::sign(5, 11, self::OLD_KEY)));
        $this->assertTrue($charge->verifyToken($room, $charge->mintToken($room)));
    }

    public function test_activate_is_idempotent(): void
    {
        $this->turnOn();
        LegacyCompatServiceProvider::activate($this->app);
        $charge = app(RoomCharge::class);

        $this->assertInstanceOf(LegacyRoomCharge::class, $charge);
        $this->assertNotInstanceOf(LegacyRoomCharge::class, $charge->inner());
    }

    /** J56(a): old-key tokens work only BEFORE the configured end date; current-key tokens are never affected. */
    public function test_old_key_tokens_stop_at_the_grace_end_date(): void
    {
        $charge = $this->turnOn(null, '2027-04-26 00:00:00');
        $room = $this->room(5, 11);
        $old = LegacyRoomTokenKeys::sign(5, 11, self::OLD_KEY);

        try {
            Carbon::setTestNow(Carbon::parse('2027-04-25 23:59:59'));
            $this->assertTrue($charge->verifyToken($room, $old), 'before the end date');

            Carbon::setTestNow(Carbon::parse('2027-04-26 00:00:00'));
            $this->assertFalse($charge->verifyToken($room, $old), 'at the end date');

            Carbon::setTestNow(Carbon::parse('2027-10-01'));
            $this->assertFalse($charge->verifyToken($room, $old), 'after the end date');
            $this->assertTrue($charge->verifyToken($room, $charge->mintToken($room)), 'current key unaffected');
        } finally {
            Carbon::setTestNow();
        }
    }

    /** J56(a): the end date must be set at cut-over; empty or garbage means old-key tokens are refused. */
    public function test_an_unset_or_unparsable_end_date_refuses_old_key_tokens(): void
    {
        $old = LegacyRoomTokenKeys::sign(5, 11, self::OLD_KEY);

        $this->assertFalse($this->turnOn(null, '')->verifyToken($this->room(5, 11), $old));
        // Relative values would be re-evaluated on every check and never expire: refused like garbage.
        foreach (['not a date', '+6 months', 'tomorrow', 'next year', '2027-13-01', '2027-02-30', '26/04/2027', '2027-02-30 10:00', '2099-01-01T25:00:00Z', '2099-01-01 10:61'] as $bad) {
            config(['legacy_compat.room_token_legacy_until' => $bad]);
            $this->assertFalse(app(RoomCharge::class)->verifyToken($this->room(5, 11), $old), $bad);
            $this->assertNull(LegacyRoomTokenKeys::graceEndsAt(), $bad);
        }
    }

    public function test_absolute_dates_and_iso_date_times_are_accepted(): void
    {
        foreach (['2099-01-01', '2099-01-01 08:30:00', '2099-01-01T08:30:00Z', '2099-01-01T08:30+02:00'] as $good) {
            config(['legacy_compat.room_token_legacy_until' => $good]);
            $this->assertNotNull(LegacyRoomTokenKeys::graceEndsAt(), $good);
        }
        config(['legacy_compat.room_token_legacy_until' => '2099-01-01']);
        $this->assertSame('2099-01-01 00:00:00', LegacyRoomTokenKeys::graceEndsAt()?->format('Y-m-d H:i:s'));
    }
}
