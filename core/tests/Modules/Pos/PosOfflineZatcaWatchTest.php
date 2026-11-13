<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Modules\Fiscal\Contracts\ZatcaOfflineTills;
use Juwri\Modules\Pos\Models\PosOfflineDevice;
use Juwri\Modules\Pos\Models\PosOfflineZatcaInvoice;
use Juwri\Modules\Pos\Models\PosOfflineZatcaUnit;
use Juwri\Modules\Pos\Services\Offline\OfflineZatcaWatch;
use Tests\TestCase;

/**
 * P6-94: fiscal:zatca-check watches every offline till that is its own ZATCA unit (OP-12): certificate expiry, invoices
 * pending against the 24 h reporting window, ICV gaps / PIH forks, tills that stopped syncing with invoices waiting.
 * Each rule fires and clears with fake time; findings stay in their store; nothing calls ZATCA (Http is blocked).
 */
class PosOfflineZatcaWatchTest extends TestCase
{
    use RefreshDatabase;

    private int $a = 9401;

    private int $b = 9402;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfHour());
        $this->seedStores($this->a, $this->b);
        foreach ([$this->a, $this->b] as $s) {
            StoreOption::updateStoreOption($s, 'fiscal_vat_number', '300000000000003');
            StoreOption::updateStoreOption($s, 'fiscal_legal_name', 'Co '.$s);
        }
        config(['services.zatca.environment' => 'sandbox', 'services.zatca.live' => false]);
    }

    private function token(int $days): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $x509 = openssl_csr_sign(openssl_csr_new(['commonName' => 'TILL'], $key), null, $key, $days);
        openssl_x509_export($x509, $pem);

        return base64_encode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem));
    }

    private function unit(int $store, string $name, int $certDays = 200): PosOfflineZatcaUnit
    {
        $account = (int) DB::table('stores')->where('id', $store)->value('account_id');
        $deviceId = 'till-'.Str::lower(Str::random(8));
        $device = PosOfflineDevice::query()->withoutGlobalScopes()->create(['account_id' => $account, 'store_id' => $store, 'device_id' => $deviceId,
            'name' => $name, 'last_seen_at' => now()]);

        return PosOfflineZatcaUnit::query()->withoutGlobalScopes()->create(['account_id' => $account, 'store_id' => $store, 'pos_offline_device_id' => $device->id,
            'device_id' => $deviceId, 'status' => 'production', 'public_key' => 'pk', 'compliance_request_id' => 'r', 'compliance_certificate' => 'c',
            'compliance_secret' => 's', 'production_certificate' => $this->token($certDays), 'production_secret' => 's', 'production_at' => now()]);
    }

    private function invoice(PosOfflineZatcaUnit $u, int $icv, string $chain = 'ok', string $status = 'pending'): PosOfflineZatcaInvoice
    {
        return PosOfflineZatcaInvoice::query()->withoutGlobalScopes()->create(['account_id' => $u->account_id, 'store_id' => $u->store_id, 'unit_id' => $u->id,
            'device_id' => $u->device_id, 'icv' => $icv, 'uuid' => (string) Str::uuid(), 'invoice_hash' => Str::random(44), 'previous_hash' => Str::random(44),
            'xml' => '<Invoice/>', 'signature' => 'sig', 'chain' => $chain, 'status' => $status]);
    }

    /** @return list<string> "LEVEL detail" for one store */
    private function found(int $store): array
    {
        return array_map(fn ($f) => $f['level'].' '.$f['detail'], app(ZatcaOfflineTills::class)->findings($store));
    }

    private function has(int $store, string $level, string $needle): bool
    {
        return collect($this->found($store))->contains(fn ($l) => str_starts_with($l, $level.' ') && str_contains($l, $needle));
    }

    public function test_the_pos_module_binds_the_watch(): void
    {
        $this->assertInstanceOf(OfflineZatcaWatch::class, app(ZatcaOfflineTills::class));
    }

    public function test_a_healthy_till_has_no_findings_and_the_check_passes_strict(): void
    {
        $u = $this->unit($this->a, 'Front');
        $this->invoice($u, 1, 'ok', 'reported');
        $this->assertSame([], $this->found($this->a));
        config(['services.zatca.environment' => 'production', 'services.zatca.live' => true]);
        $this->artisan('fiscal:zatca-check', ['--store' => $this->a])->doesntExpectOutputToContain('till')->assertSuccessful();
    }

    public function test_certificate_expiry_fails_at_14_days_warns_inside_the_renew_window_and_clears_on_reonboarding(): void
    {
        $u = $this->unit($this->a, 'Front', 10);
        $this->assertTrue($this->has($this->a, 'FAIL', 'expires in'));
        $u->forceFill(['production_certificate' => $this->token(30)])->save();
        $this->assertTrue($this->has($this->a, 'WARN', 'expires in'));
        $this->assertFalse($this->has($this->a, 'FAIL', 'expires'));
        $this->travel(31)->days();
        PosOfflineDevice::query()->withoutGlobalScopes()->whereKey($u->pos_offline_device_id)->update(['last_seen_at' => now()]);
        $this->assertTrue($this->has($this->a, 'FAIL', 'EXPIRED'));
        $u->forceFill(['production_certificate' => $this->token(400)])->save();
        $this->assertSame([], $this->found($this->a));
        $u->forceFill(['production_certificate' => 'not-a-cert'])->save();
        $this->assertTrue($this->has($this->a, 'WARN', 'expiry unknown'));
    }

    public function test_pending_invoices_warn_then_fail_after_20_hours_and_clear_once_reported(): void
    {
        config(['pos.offline.zatca_watch.pending_warn_hours' => 6]);
        $u = $this->unit($this->a, 'Front');
        $inv = $this->invoice($u, 1);
        $this->assertSame([], $this->found($this->a));
        $this->travel(7)->hours();
        PosOfflineDevice::query()->withoutGlobalScopes()->whereKey($u->pos_offline_device_id)->update(['last_seen_at' => now()]);
        $this->assertTrue($this->has($this->a, 'WARN', 'oldest 7 h'));
        $this->travel(14)->hours();
        PosOfflineDevice::query()->withoutGlobalScopes()->whereKey($u->pos_offline_device_id)->update(['last_seen_at' => now()]);
        $this->assertTrue($this->has($this->a, 'FAIL', 'oldest 21 h old (24 h limit)'));
        $inv->forceFill(['status' => 'reported', 'reported_at' => now()])->save();
        $this->assertSame([], $this->found($this->a));
    }

    public function test_the_issue_time_in_the_xml_counts_when_the_invoice_was_uploaded_later(): void
    {
        $u = $this->unit($this->a, 'Front');
        $inv = $this->invoice($u, 1);
        $issued = now()->subHours(22)->setTimezone('Asia/Riyadh');
        $inv->forceFill(['xml' => '<Invoice><cbc:IssueDate>'.$issued->format('Y-m-d').'</cbc:IssueDate><cbc:IssueTime>'.$issued->format('H:i:s').'</cbc:IssueTime></Invoice>'])->save();
        $this->assertTrue($this->has($this->a, 'FAIL', 'oldest 22 h'));
    }

    public function test_icv_gaps_and_chain_forks_fail_and_a_filled_gap_clears(): void
    {
        $u = $this->unit($this->a, 'Front');
        $this->invoice($u, 1, 'ok', 'reported');
        $gap = $this->invoice($u, 3, 'gap', 'reported');
        $this->assertTrue($this->has($this->a, 'FAIL', 'ICV gap'));
        $gap->forceFill(['chain' => 'ok'])->save(); // ICV 2 arrived and linked
        $this->assertSame([], $this->found($this->a));
        $this->invoice($u, 4, 'fork', 'reported');
        $this->assertTrue($this->has($this->a, 'FAIL', 'fork the hash chain'));
        $this->travel(8)->days(); // reviewed history outside chain_days stops alerting
        PosOfflineDevice::query()->withoutGlobalScopes()->whereKey($u->pos_offline_device_id)->update(['last_seen_at' => now()]);
        $u->forceFill(['production_certificate' => $this->token(400)])->save();
        $this->assertSame([], $this->found($this->a));
    }

    public function test_a_till_that_stopped_syncing_with_pending_invoices_warns_then_fails_and_clears_when_it_syncs(): void
    {
        config(['pos.offline.zatca_watch.pending_warn_hours' => 100]);
        $u = $this->unit($this->a, 'Back');
        $this->invoice($u, 1);
        $this->travel(13)->hours();
        $this->assertTrue($this->has($this->a, 'WARN', 'not synced for 13 h'));
        $this->travel(12)->hours();
        $this->assertTrue($this->has($this->a, 'FAIL', 'not synced for 25 h'));
        PosOfflineDevice::query()->withoutGlobalScopes()->whereKey($u->pos_offline_device_id)->update(['last_seen_at' => now()]);
        $this->assertFalse($this->has($this->a, 'FAIL', 'not synced'));
        $this->assertFalse($this->has($this->a, 'WARN', 'not synced'));
    }

    public function test_an_unsynced_till_with_nothing_pending_is_fine_and_a_retired_unit_counts_only_while_it_holds_pending_invoices(): void
    {
        $u = $this->unit($this->a, 'Old');
        $this->travel(3)->days();
        $this->assertSame([], $this->found($this->a));
        $inv = $this->invoice($u, 1);
        $u->forceFill(['status' => 'retired', 'retired_at' => now()])->save();
        $this->travel(21)->hours();
        $this->assertTrue($this->has($this->a, 'FAIL', 'oldest 21 h'));
        $inv->forceFill(['status' => 'reported'])->save();
        $this->assertSame([], $this->found($this->a));
        $this->assertSame([], app(ZatcaOfflineTills::class)->status($this->a));
    }

    public function test_findings_and_status_stay_in_their_store(): void
    {
        $this->unit($this->a, 'Till A', 5);
        $b = $this->unit($this->b, 'Till B');
        $this->invoice($b, 1);
        $this->assertTrue($this->has($this->a, 'FAIL', 'expires in'));
        $this->assertSame([], $this->found($this->b));
        $all = app(ZatcaOfflineTills::class)->findings();
        $this->assertSame([$this->a], array_values(array_unique(array_column($all, 'store_id'))));
        $this->assertStringContainsString('Till A', $all[0]['till']);

        $sa = app(ZatcaOfflineTills::class)->status($this->a);
        $sb = app(ZatcaOfflineTills::class)->status($this->b);
        $this->assertCount(1, $sa);
        $this->assertSame(0, $sa[0]['pending']);
        $this->assertSame(1, $sb[0]['pending']);
        $this->assertSame(0, $sb[0]['oldest_pending_hours']);
        $this->assertStringContainsString($b->device_id, $sb[0]['till']);
        $this->assertNotNull($sb[0]['cert_expires_at']);

        $this->artisan('fiscal:zatca-check', ['--store' => $this->b])->doesntExpectOutputToContain('Till A')->assertSuccessful();
        $this->artisan('fiscal:zatca-check', ['--store' => $this->a])->expectsOutputToContain('Till A')->assertFailed();
    }

    public function test_the_check_fails_on_offline_findings_and_alert_sends_store_and_till_to_the_fiscal_channel(): void
    {
        $u = $this->unit($this->a, 'Front', 7);
        $this->artisan('fiscal:zatca-check')->expectsOutputToContain("store {$this->a} / till Front")->assertFailed();
        $this->assertSame(0, PlatformAlert::query()->count(), 'no alert without --alert');

        $this->artisan('fiscal:zatca-check', ['--alert' => true])->assertFailed();
        $alert = PlatformAlert::query()->where('kind', 'fiscal')->firstOrFail();
        $this->assertStringContainsString("store {$this->a} / till Front ({$u->device_id})", $alert->message);
        $this->assertStringContainsString('expires in', $alert->message);

        $u->forceFill(['production_certificate' => $this->token(400)])->save();
        $this->artisan('fiscal:zatca-check', ['--alert' => true, '--store' => $this->a])->assertSuccessful();
        $this->assertSame(1, PlatformAlert::query()->where('kind', 'fiscal')->count());
    }

    public function test_warnings_fail_only_with_strict(): void
    {
        config(['services.zatca.environment' => 'production', 'services.zatca.live' => true]);
        $this->unit($this->a, 'Front', 30);
        $this->artisan('fiscal:zatca-check', ['--store' => $this->a])->expectsOutputToContain('Till production certificate expires in')->assertSuccessful();
        $this->artisan('fiscal:zatca-check', ['--store' => $this->a, '--strict' => true])->assertFailed();
    }
}
