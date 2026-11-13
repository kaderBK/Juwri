<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Offline;

use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Contracts\ZatcaDeviceUnits;
use Juwri\Modules\Pos\Models\PosOfflineDevice;
use Juwri\Modules\Pos\Models\PosOfflineZatcaInvoice;
use Juwri\Modules\Pos\Models\PosOfflineZatcaUnit;

/**
 * OP-12, server side of per-till ZATCA signing (owner Q6 option A; gated by OP-0 → config pos.offline.zatca_device_signing).
 *
 *  onboard     the till sends its CSR (key made on the device, never exported) + the owner's FATOORA OTP → compliance CSID
 *  compliance  the till signs the compliance samples, the server runs FATOORA's checks → production CSID
 *  accept      after sync, each invoice the till signed: hash recomputed here, signature checked with the unit's public
 *              key, ICV / PIH read from the XML, then the chain check against what the server already holds:
 *                ok   = its PIH is the hash of the invoice at ICV-1 (or ZATCA's first PIH at ICV 1)
 *                gap  = no invoice at ICV-1 yet (becomes ok when it arrives and links)
 *                fork = another invoice already holds this ICV, or its PIH is not the hash at ICV-1
 *              Every issued invoice is kept and reported whatever the chain says (it was handed to a customer); the
 *              chain result is for the owner to review. Same invoice again = duplicate (exactly once).
 *  report      pending invoices of production units → FATOORA reporting (accepted → reported, refused → rejected,
 *              transport error → stays pending, retried by pos:offline-zatca-report).
 */
class OfflineZatca
{
    public function __construct(private readonly ZatcaDeviceUnits $zatca) {}

    public static function enabled(): bool
    {
        return (bool) config('pos.offline.zatca_device_signing', false);
    }

    public function active(PosOfflineDevice $device): ?PosOfflineZatcaUnit
    {
        return PosOfflineZatcaUnit::query()->where('store_id', $device->store_id)->where('pos_offline_device_id', $device->id)
            ->where('status', '!=', 'retired')->latest('id')->first();
    }

    /** New onboarding (a wiped till has a new key): the till's previous unit is retired, its invoices stay. */
    public function onboard(Store $store, PosOfflineDevice $device, string $csrPem, string $otp): PosOfflineZatcaUnit
    {
        $c = $this->zatca->complianceForCsr($csrPem, $otp); // throws before anything changes

        return DB::transaction(function () use ($store, $device, $c) {
            PosOfflineZatcaUnit::query()->where('store_id', $store->id)->where('pos_offline_device_id', $device->id)
                ->where('status', '!=', 'retired')->update(['status' => 'retired', 'retired_at' => now()]);

            return PosOfflineZatcaUnit::query()->create([
                'account_id' => $store->account_id, 'store_id' => $store->id, 'pos_offline_device_id' => $device->id, 'device_id' => $device->device_id,
                'status' => 'compliance', 'public_key' => $c['public_pem'], 'compliance_request_id' => $c['request_id'],
                'compliance_certificate' => $c['certificate'], 'compliance_secret' => $c['secret'],
            ]);
        });
    }

    /**
     * @param  list<array{xml: string, signature: string}>  $samples
     * @return bool true when every sample passed and the unit is now in production
     */
    public function compliance(PosOfflineZatcaUnit $unit, array $samples): bool
    {
        if ($unit->status !== 'compliance' || $samples === []) {
            return false;
        }
        foreach ($samples as $s) {
            $v = $this->zatca->verifyInvoice((string) $s['xml'], (string) $s['signature'], (string) $unit->public_key); // signed by THIS unit's key
            if (! $this->zatca->complianceCheck((string) $unit->compliance_certificate, (string) $unit->compliance_secret, ['xml' => (string) $s['xml'], 'hash' => $v['hash'], 'uuid' => $v['uuid']])) {
                return false;
            }
        }
        $p = $this->zatca->production((string) $unit->compliance_request_id, (string) $unit->compliance_certificate, (string) $unit->compliance_secret);
        $unit->forceFill(['status' => 'production', 'production_certificate' => $p['certificate'], 'production_secret' => $p['secret'], 'production_at' => now()])->save();

        return true;
    }

    /**
     * One uploaded invoice. Refused (nothing stored) only when it is not a valid invoice of this unit at all.
     *
     * @param  array{xml?: mixed, signature?: mixed, client_key?: mixed}  $in
     * @return array{status: string, icv?: int, chain?: string, code?: string}
     */
    public function accept(PosOfflineZatcaUnit $unit, array $in): array
    {
        if ($unit->status !== 'production') {
            return ['status' => 'refused', 'code' => 'unit_not_in_production'];
        }
        try {
            $v = $this->zatca->verifyInvoice((string) ($in['xml'] ?? ''), (string) ($in['signature'] ?? ''), (string) $unit->public_key);
        } catch (\InvalidArgumentException $e) {
            return ['status' => 'refused', 'code' => $e->getMessage()];
        }
        $key = isset($in['client_key']) && is_string($in['client_key']) && strlen($in['client_key']) <= 36 ? $in['client_key'] : null;

        return DB::transaction(function () use ($unit, $v, $in, $key) {
            $locked = PosOfflineZatcaUnit::query()->whereKey($unit->id)->lockForUpdate()->firstOrFail();
            $same = PosOfflineZatcaInvoice::query()->where('unit_id', $locked->id)->where('invoice_hash', $v['hash'])->first();
            if ($same !== null) {
                return ['status' => 'duplicate', 'icv' => (int) $same->icv, 'chain' => (string) $same->chain];
            }
            $row = PosOfflineZatcaInvoice::query()->create([
                'account_id' => $locked->account_id, 'store_id' => $locked->store_id, 'unit_id' => $locked->id, 'device_id' => $locked->device_id,
                'icv' => $v['icv'], 'uuid' => $v['uuid'], 'invoice_hash' => $v['hash'], 'previous_hash' => $v['pih'],
                'xml' => (string) $in['xml'], 'signature' => (string) $in['signature'], 'client_key' => $key,
                'chain' => $this->chainOf($locked, $v['icv'], $v['hash'], $v['pih']), 'status' => 'pending',
            ]);
            // two invoices at one ICV: both are forks (neither may pass as the chain)
            if (PosOfflineZatcaInvoice::query()->where('unit_id', $locked->id)->where('icv', $v['icv'])->count() > 1) {
                PosOfflineZatcaInvoice::query()->where('unit_id', $locked->id)->where('icv', $v['icv'])->update(['chain' => 'fork']);
            }
            // the invoice right after it may have been waiting for this one (gap → ok), or now forks from it
            PosOfflineZatcaInvoice::query()->where('unit_id', $locked->id)->where('icv', $v['icv'] + 1)->where('chain', 'gap')->get()
                ->each(fn (PosOfflineZatcaInvoice $next) => $next->forceFill(['chain' => $this->chainOf($locked, (int) $next->icv, (string) $next->invoice_hash, (string) $next->previous_hash)])->save());
            if ($v['icv'] > (int) $locked->last_icv) {
                $locked->forceFill(['last_icv' => $v['icv'], 'last_hash' => $v['hash']])->save();
            }

            return ['status' => 'accepted', 'icv' => (int) $row->icv, 'chain' => (string) $row->fresh()?->chain];
        });
    }

    private function chainOf(PosOfflineZatcaUnit $unit, int $icv, string $hash, string $pih): string
    {
        $rivals = PosOfflineZatcaInvoice::query()->where('unit_id', $unit->id)->where('icv', $icv)->where('invoice_hash', '!=', $hash)->exists();
        if ($rivals) {
            return 'fork';
        }
        if ($icv === 1) {
            return hash_equals($this->zatca->firstPih(), $pih) ? 'ok' : 'fork';
        }
        $prev = PosOfflineZatcaInvoice::query()->where('unit_id', $unit->id)->where('icv', $icv - 1)->pluck('invoice_hash');
        if ($prev->isEmpty()) {
            return 'gap';
        }

        return $prev->contains(fn ($h) => hash_equals((string) $h, $pih)) && $prev->count() === 1 ? 'ok' : 'fork';
    }

    /**
     * Report pending invoices (oldest first).
     *
     * @return array{reported: int, rejected: int, failed: int}
     */
    public function report(?int $storeId = null, int $limit = 200): array
    {
        $out = ['reported' => 0, 'rejected' => 0, 'failed' => 0];
        $rows = PosOfflineZatcaInvoice::query()->withoutGlobalScopes()->where('status', 'pending')
            ->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->orderBy('unit_id')->orderBy('icv')->limit($limit)->get();
        $units = PosOfflineZatcaUnit::query()->withoutGlobalScopes()->whereIn('id', $rows->pluck('unit_id')->unique())->get()->keyBy('id');
        foreach ($rows as $row) {
            $unit = $units[$row->unit_id] ?? null;
            if ($unit === null || $unit->production_certificate === null) {
                continue;
            }
            try {
                $r = $this->zatca->report((string) $unit->production_certificate, (string) $unit->production_secret, ['xml' => (string) $row->xml, 'hash' => (string) $row->invoice_hash, 'uuid' => (string) $row->uuid]);
            } catch (\Throwable $e) {
                $row->forceFill(['attempts' => $row->attempts + 1])->save();
                $out['failed']++;

                continue;
            }
            $ok = $r['status'] === 'accepted';
            $row->forceFill(['status' => $ok ? 'reported' : 'rejected', 'attempts' => $row->attempts + 1, 'report_response' => $r['response'], 'reported_at' => $ok ? now() : null])->save();
            $out[$ok ? 'reported' : 'rejected']++;
        }

        return $out;
    }
}
