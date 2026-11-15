<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Juwri\Modules\Pos\Models\PosOfflineDevice;
use Tests\TestCase;

/**
 * Offline POS OP-2 (spec §6): tills register (token kept only as a hash), replay only from a registered till, the owner
 * lists and revokes tills, a revoked till may still upload what it holds and is told to wipe (then reports it and its
 * token dies), every contact renews a 3-day lease, and the staff PIN check offline uses a per-till pepper. Tenancy: a
 * till of one store is unknown to every other store.
 */
class PosOfflineDeviceTest extends TestCase
{
    use OfflineTillFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array{0: string, 1: string} device id, token
     */
    private function register(array $t, string $id = 'till-0001-abcdef'): array
    {
        $r = $this->asStaff($t['cashier'])->postJson(route('pos.offline.devices.register'), ['device_id' => $id, 'name' => 'Front till', 'platform' => 'web'])->assertCreated();

        return [$r->json('device_id'), $r->json('token')];
    }

    /** @return array<string, string> */
    private function h(string $id, string $token): array
    {
        return ['X-Pos-Device' => $id, 'X-Pos-Device-Token' => $token];
    }

    public function test_register_keeps_only_a_hash_and_replay_needs_a_registered_till(): void
    {
        $t = $this->make();
        [$id, $token] = $this->register($t);
        $row = PosOfflineDevice::query()->where('device_id', $id)->first();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, (string) json_encode(DB::table('pos_offline_devices')->get()));
        $this->assertNotSame((string) $row->pepper, (string) DB::table('pos_offline_devices')->where('id', $row->id)->value('pepper'), 'the pepper is encrypted at rest');

        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $body = ['device_id' => $id, 'branch_id' => $t['branch']->id, 'records' => [$sale]];
        $this->asStaff($t['cashier'])->postJson(route('pos.offline.replay'), $body)->assertStatus(401)->assertJson(['code' => 'device_unknown']);
        $this->asStaff($t['cashier'])->withHeaders($this->h($id, str_repeat('0', 64)))->postJson(route('pos.offline.replay'), $body)->assertStatus(401);
        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->postJson(route('pos.offline.replay'), ['device_id' => 'till-other-0001'] + $body)->assertStatus(401);
        $ok = $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->postJson(route('pos.offline.replay'), $body)->assertOk();
        $this->assertSame(['accepted', false], [$ok->json('results.0.status'), $ok->json('wipe')]);
        $this->assertNotNull($ok->json('lease_until'));
    }

    public function test_the_owner_lists_and_revokes_tills_staff_cannot(): void
    {
        $t = $this->make();
        [$id] = $this->register($t);
        $owner = User::find($t['store']->user_id);
        $this->asStaff($t['manager'])->getJson(route('pos.offline.devices'))->assertForbidden();
        $list = $this->actingAs($owner, 'web')->getJson(route('pos.offline.devices', ['store' => $t['store']->id]))->assertOk()->json('devices');
        $this->assertSame([[$id, 'Front till', null]], array_map(fn ($d) => [$d['device_id'], $d['name'], $d['revoked_at']], $list));
        $this->assertArrayNotHasKey('token_hash', $list[0]);
        $this->assertArrayNotHasKey('pepper', $list[0]);

        $this->asStaff($t['manager'])->postJson(route('pos.offline.devices.revoke', $list[0]['id']))->assertForbidden();
        $this->actingAs($owner, 'web')->postJson(route('pos.offline.devices.revoke', ['id' => $list[0]['id'], 'store' => $t['store']->id]))->assertOk();
        $this->assertNotNull(PosOfflineDevice::query()->where('device_id', $id)->value('revoked_at'));
    }

    public function test_a_revoked_till_uploads_what_it_holds_is_told_to_wipe_and_its_token_dies(): void
    {
        $t = $this->make();
        [$id, $token] = $this->register($t);
        PosOfflineDevice::query()->where('device_id', $id)->update(['revoked_at' => now()]);

        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->getJson(route('pos.offline.devices.status'))->assertOk()->assertJson(['revoked' => true, 'wipe' => true, 'lease_until' => null]);
        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->assertStatus(403)->assertJson(['wipe' => true]);
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $up = $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->postJson(route('pos.offline.replay'), ['device_id' => $id, 'branch_id' => $t['branch']->id, 'records' => [$sale]])->assertOk();
        $this->assertSame(['accepted', true], [$up->json('results.0.status'), $up->json('wipe')], 'what it holds is not lost');

        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->postJson(route('pos.offline.devices.wiped'))->assertOk();
        $this->assertNotNull(PosOfflineDevice::query()->where('device_id', $id)->value('wiped_at'));
        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->getJson(route('pos.offline.devices.status'))->assertStatus(401);
        $this->asStaff($t['cashier'])->postJson(route('pos.offline.devices.register'), ['device_id' => $id])->assertStatus(403)->assertJson(['code' => 'device_revoked']);
    }

    public function test_the_owner_can_wipe_a_till_that_stays_in_service_after_it_uploads(): void
    {
        $t = $this->make();
        [$id, $token] = $this->register($t);
        $owner = User::find($t['store']->user_id);
        $row = PosOfflineDevice::query()->where('device_id', $id)->first();
        $this->asStaff($t['manager'])->postJson(route('pos.offline.devices.wipe', $row->id))->assertForbidden();
        $this->actingAs($owner, 'web')->postJson(route('pos.offline.devices.wipe', ['id' => $row->id, 'store' => $t['store']->id]))->assertOk();

        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->getJson(route('pos.offline.devices.status'))->assertOk()->assertJson(['revoked' => false, 'wipe' => true]);
        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->assertStatus(403)->assertJson(['code' => 'wipe_requested']);
        $sale = $this->sale($t, [['item_id' => $t['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->postJson(route('pos.offline.replay'), ['device_id' => $id, 'branch_id' => $t['branch']->id, 'records' => [$sale]])
            ->assertOk()->assertJsonPath('results.0.status', 'accepted')->assertJsonPath('wipe', true);
        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->postJson(route('pos.offline.devices.wiped'))->assertOk();

        // it stays in service: a manager signs in online and the till registers again, with a new token and pepper
        [, $token2] = $this->register($t, $id);
        $this->assertNotSame($token, $token2);
        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token2))->getJson(route('pos.offline.devices.status'))->assertOk()->assertJson(['wipe' => false]);
        $this->assertSame((int) $owner->id, (int) $row->fresh()->wipe_requested_by_user_id, 'who asked for the wipe is kept');
    }

    public function test_every_contact_renews_a_three_day_lease_and_the_web_till_wipes_when_it_ends(): void
    {
        $t = $this->make();
        [$id, $token] = $this->register($t);
        $this->travelTo(now()->startOfMinute());
        $lease = $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->getJson(route('pos.offline.devices.status'))->json('lease_until');
        $this->assertSame(now()->addDays(3)->toIso8601ZuluString(), $lease);
        // The till's start-up rules (lease over → snapshot + PINs wiped, queue kept; 7-day retention; revoked → upload
        // then wipe) are the pure TillPolicy in pos-device.js, tested path by path in tests/js/pos/till-policy.test.mjs.
        $js = (string) file_get_contents(base_path('../assets/pos/pos-device.js'));
        $this->assertStringContainsString('if (left.length) { this.salesBlocked', $js, 'the queue is never deleted while records are left');
    }

    public function test_the_snapshot_carries_pin_verifiers_peppered_per_till_and_never_the_pin(): void
    {
        $t = $this->make();
        $t['cashier']->rememberOfflinePin('4321');
        $t['cashier']->save();
        [$a, $ta] = $this->register($t, 'till-aaaa-000001');
        [$b, $tb] = $this->register($t, 'till-bbbb-000002');
        $docA = $this->asStaff($t['cashier'])->withHeaders($this->h($a, $ta))->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->assertOk()->json();
        $docB = $this->asStaff($t['cashier'])->withHeaders($this->h($b, $tb))->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->assertOk()->json();
        $va = (array) collect((array) $docA['device']['pin_verifiers'])->firstWhere('staff_id', $t['cashier']->id);
        $vb = (array) collect((array) $docB['device']['pin_verifiers'])->firstWhere('staff_id', $t['cashier']->id);
        $this->assertNotSame($va['verifier'], $vb['verifier'], 'a different pepper on every till');
        $this->assertSame($docA['hash'], $docB['hash'], 'the shared snapshot is the same; verifiers are outside the hash');

        // What the till does offline: PBKDF2(PIN, salt) then HMAC with its own pepper (kept on the till).
        $pepperA = (string) PosOfflineDevice::query()->where('device_id', $a)->first()->pepper;
        $this->assertSame($va['verifier'], hash_hmac('sha256', hash_pbkdf2('sha256', '4321', $va['salt'], $va['rounds'], 64), $pepperA));
        $this->assertNotSame($va['verifier'], hash_hmac('sha256', hash_pbkdf2('sha256', '1234', $va['salt'], $va['rounds'], 64), $pepperA));
        $staffA = (array) collect((array) $docA['parts']['staff'])->firstWhere('id', $t['cashier']->id);
        $this->assertSame(['id', 'name', 'permissions', 'pin_rev', 'role'], array_keys($staffA));
        $this->assertSame(['staff_id', 'salt', 'rounds', 'verifier'], array_keys($va));
        $this->assertNotContains('4321', Arr::dot($docA));
        $json = (string) json_encode($docA);
        $this->assertStringNotContainsString((string) $t['cashier']->fresh()->pin_offline_kdf, $json, 'the verifier source never leaves the server');
        $this->assertNull($this->asStaff($t['cashier'])->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->json('device'), 'no till, no verifiers');

        $v1 = $docA['version'];
        $t['cashier']->rememberOfflinePin('9999');
        $t['cashier']->save();
        $this->assertSame($v1 + 1, $this->asStaff($t['cashier'])->withHeaders($this->h($a, $ta))->getJson(route('pos.offline.snapshot', ['branch' => $t['branch']->id]))->json('version'), 'a PIN change is a new version');
    }

    public function test_setting_a_pin_or_signing_in_with_one_writes_the_offline_source(): void
    {
        $t = $this->make();
        $legacy = StaffMember::create(['owner_id' => $t['store']->user_id, 'store_id' => $t['store']->id, 'name' => 'Legacy', 'email' => Str::random(6).'@t.test',
            'password' => 'x', 'role' => 'cashier', 'active' => 1, 'pin' => '5678']);
        $this->assertNull($legacy->pin_offline_kdf);
        $this->assertSame($legacy->id, StaffMember::authenticateByPin($t['store']->id, '5678')?->id);
        $fresh = $legacy->fresh();
        $this->assertSame(hash_pbkdf2('sha256', '5678', $fresh->pin_offline_salt, StaffMember::OFFLINE_PIN_ROUNDS, 64), $fresh->pin_offline_kdf);
        $this->assertArrayNotHasKey('pin_offline_kdf', $fresh->toArray());
    }

    public function test_a_till_of_one_store_is_unknown_to_every_other_store(): void
    {
        $a = $this->make();
        $b = $this->make();
        [$id, $token] = $this->register($a);
        $sale = $this->sale($b, [['item_id' => $b['item']->id, 'unit_minor' => 1050, 'qty' => 1]]);
        $this->asStaff($b['cashier'])->withHeaders($this->h($id, $token))->postJson(route('pos.offline.replay'), ['device_id' => $id, 'branch_id' => $b['branch']->id, 'records' => [$sale]])->assertStatus(401);
        $this->asStaff($b['cashier'])->withHeaders($this->h($id, $token))->getJson(route('pos.offline.devices.status'))->assertStatus(401);
        $ownerB = User::find($b['store']->user_id);
        $this->assertSame([], $this->actingAs($ownerB, 'web')->getJson(route('pos.offline.devices', ['store' => $b['store']->id]))->json('devices'));
        $rowA = PosOfflineDevice::query()->withoutGlobalScopes()->where('device_id', $id)->first();
        $this->actingAs($ownerB, 'web')->postJson(route('pos.offline.devices.revoke', ['id' => $rowA->id, 'store' => $b['store']->id]))->assertNotFound();
        $this->assertNull($rowA->fresh()->revoked_at);
        $this->assertSame((int) $a['store']->account_id, (int) $rowA->account_id);
    }

    public function test_the_escrow_key_goes_only_to_its_own_live_till_and_is_encrypted_at_rest(): void
    {
        $t = $this->make();
        [$id, $token] = $this->register($t);
        $this->asStaff($t['cashier'])->getJson(route('pos.offline.devices.escrow'))->assertStatus(401);
        $key = $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->getJson(route('pos.offline.devices.escrow'))->assertOk()->json('escrow');
        $this->assertSame(32, strlen((string) base64_decode((string) $key, true)));
        $this->assertSame($key, $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->getJson(route('pos.offline.devices.escrow'))->json('escrow'), 'stable across reloads');
        [, $token2] = $this->register($t, $id);
        $this->assertSame($key, $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token2))->getJson(route('pos.offline.devices.escrow'))->json('escrow'), 're-registering keeps the key (the outbox stays readable)');

        $row = PosOfflineDevice::query()->withoutGlobalScopes()->where('device_id', $id)->sole();
        $raw = (string) DB::table('pos_offline_devices')->where('id', $row->id)->value('escrow');
        $this->assertNotSame($key, $raw);
        $this->assertStringNotContainsString($key, $raw);
        $this->assertArrayNotHasKey('escrow', $row->toArray());

        $b = $this->make();
        $this->asStaff($b['cashier'])->withHeaders($this->h($id, $token2))->getJson(route('pos.offline.devices.escrow'))->assertStatus(401);

        $row->update(['wipe_requested_at' => now()]);
        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token2))->getJson(route('pos.offline.devices.escrow'))->assertStatus(403);
        $row->update(['wipe_requested_at' => null, 'revoked_at' => now()]);
        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token2))->getJson(route('pos.offline.devices.escrow'))->assertStatus(403);
    }

    public function test_a_wiped_till_that_registers_again_starts_its_sequence_afresh_and_a_plain_re_registration_does_not(): void
    {
        $t = $this->make();
        [$id, $token] = $this->register($t);
        PosOfflineDevice::query()->where('device_id', $id)->update(['last_seq' => 40, 'missing_seqs' => json_encode([7, 9])]);
        [, $token] = $this->register($t, $id); // token lost, same till: its numbers go on
        $this->assertSame(40, (int) PosOfflineDevice::query()->where('device_id', $id)->value('last_seq'));

        PosOfflineDevice::query()->where('device_id', $id)->update(['wipe_requested_at' => now()->subMinute()]);
        $this->asStaff($t['cashier'])->withHeaders($this->h($id, $token))->postJson(route('pos.offline.devices.wiped'))->assertOk();
        $this->travel(1)->seconds();
        $this->register($t, $id);
        $row = PosOfflineDevice::query()->where('device_id', $id)->sole();
        $this->assertSame([0, []], [(int) $row->last_seq, array_values((array) $row->missing_seqs)]);
    }
}
