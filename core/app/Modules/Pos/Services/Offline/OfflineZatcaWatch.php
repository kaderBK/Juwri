<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Offline;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Juwri\Modules\Fiscal\Contracts\ZatcaCertificates;
use Juwri\Modules\Fiscal\Contracts\ZatcaOfflineTills;
use Juwri\Modules\Pos\Models\PosOfflineDevice;
use Juwri\Modules\Pos\Models\PosOfflineZatcaInvoice;
use Juwri\Modules\Pos\Models\PosOfflineZatcaUnit;

/**
 * P6-94: per-till ZATCA watch for offline POS (OP-12). Read-only; no ZATCA calls. Rules per unit:
 *   certificate  production certificate expired or <= 14 days → FAIL; <= services.zatca.renew_warn_days → WARN; unreadable → WARN
 *   pending      oldest pending invoice (issue time, else upload time) > 20 h of the 24 h reporting window → FAIL;
 *                > pos.offline.zatca_watch.pending_warn_hours → WARN
 *   chain        invoices flagged gap or fork in the last chain_days → FAIL (a gap clears when the missing invoice arrives)
 *   unseen       till not synced > 24 h while it has pending invoices → FAIL; > unseen_warn_hours → WARN
 * Tenancy: every query is pinned to store_id (the scheduler runs without a tenant, so global scopes are lifted).
 */
class OfflineZatcaWatch implements ZatcaOfflineTills
{
    public const CERT_FAIL_DAYS = 14;

    public const PENDING_FAIL_HOURS = 20;

    public const UNSEEN_FAIL_HOURS = 24;

    public function status(int $storeId): array
    {
        return $this->units($storeId)->map(fn (array $u) => [
            'till' => $u['till'], 'status' => (string) $u['unit']->status, 'last_sync' => $u['last_sync'], 'pending' => $u['pending'],
            'oldest_pending_hours' => $u['oldest'] ? (int) floor($u['oldest']->diffInMinutes(now()) / 60) : null,
            'cert_expires_at' => $u['expires'],
        ])->values()->all();
    }

    public function findings(?int $storeId = null): array
    {
        $out = [];
        $warnDays = (int) config('services.zatca.renew_warn_days', 45);
        $pendingWarn = (int) config('pos.offline.zatca_watch.pending_warn_hours', 12);
        $unseenWarn = (int) config('pos.offline.zatca_watch.unseen_warn_hours', 12);
        foreach ($this->units($storeId) as $u) {
            $add = function (string $level, string $detail) use (&$out, $u) {
                $out[] = ['level' => $level, 'store_id' => (int) $u['unit']->store_id, 'till' => $u['till'], 'detail' => $detail];
            };
            if ($u['unit']->status === 'production') {
                if ($u['expires'] === null) {
                    $add('WARN', 'Till certificate expiry unknown (not readable); check it in the Fatoora portal.');
                } else {
                    $days = (int) floor(now()->diffInDays($u['expires'], false));
                    if ($days < 0) {
                        $add('FAIL', 'Till production certificate EXPIRED '.abs($days).' day(s) ago: onboard the till again.');
                    } elseif ($days <= self::CERT_FAIL_DAYS) {
                        $add('FAIL', "Till production certificate expires in {$days} day(s): onboard the till again now.");
                    } elseif ($days <= $warnDays) {
                        $add('WARN', "Till production certificate expires in {$days} day(s).");
                    }
                }
            }
            if ($u['oldest'] !== null) {
                $hours = (int) floor($u['oldest']->diffInMinutes(now()) / 60);
                if ($hours > self::PENDING_FAIL_HOURS) {
                    $add('FAIL', "{$u['pending']} invoice(s) not reported to ZATCA, oldest {$hours} h old (24 h limit).");
                } elseif ($hours > $pendingWarn) {
                    $add('WARN', "{$u['pending']} invoice(s) not reported to ZATCA, oldest {$hours} h old.");
                }
            }
            if ($u['gaps'] > 0) {
                $add('FAIL', "{$u['gaps']} invoice(s) with an ICV gap (an earlier invoice has not arrived from the till).");
            }
            if ($u['forks'] > 0) {
                $add('FAIL', "{$u['forks']} invoice(s) fork the hash chain (same ICV twice or a wrong previous hash): review them.");
            }
            if ($u['pending'] > 0) {
                $unseen = $u['last_sync'] === null ? null : (int) floor($u['last_sync']->diffInMinutes(now()) / 60);
                if ($unseen === null || $unseen > self::UNSEEN_FAIL_HOURS) {
                    $add('FAIL', 'Till has not synced for '.($unseen === null ? 'ever' : "{$unseen} h").' and has invoices waiting for ZATCA.');
                } elseif ($unseen > $unseenWarn) {
                    $add('WARN', "Till has not synced for {$unseen} h and has invoices waiting for ZATCA.");
                }
            }
        }

        return $out;
    }

    /**
     * @return Collection<int, array{unit: PosOfflineZatcaUnit, till: string, last_sync: ?CarbonImmutable, pending: int, oldest: ?CarbonImmutable, expires: ?Carbon, gaps: int, forks: int}>
     */
    private function units(?int $storeId): Collection
    {
        $invoices = fn () => PosOfflineZatcaInvoice::query()->withoutGlobalScopes()->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId));
        $pending = $invoices()->where('status', 'pending')->selectRaw('unit_id, count(*) as n, min(id) as first_id')->groupBy('unit_id')->get()->keyBy('unit_id');
        $chain = $invoices()->whereIn('chain', ['gap', 'fork'])->where('created_at', '>=', now()->subDays((int) config('pos.offline.zatca_watch.chain_days', 7)))
            ->selectRaw('unit_id, chain, count(*) as n')->groupBy('unit_id', 'chain')->get();
        $units = PosOfflineZatcaUnit::query()->withoutGlobalScopes()->when($storeId !== null, fn ($q) => $q->where('store_id', $storeId))
            ->where(fn ($q) => $q->where('status', '!=', 'retired')->orWhereIn('id', $pending->keys()->all()))->orderBy('store_id')->orderBy('id')->get();
        $devices = PosOfflineDevice::query()->withoutGlobalScopes()->whereIn('id', $units->pluck('pos_offline_device_id')->unique()->all())->get()->keyBy('id');

        return $units->map(function (PosOfflineZatcaUnit $unit) use ($pending, $chain, $devices) {
            $device = $devices->get($unit->pos_offline_device_id);
            $p = $pending->get($unit->id);
            $oldest = null;
            if ($p !== null) {
                $first = PosOfflineZatcaInvoice::query()->withoutGlobalScopes()->where('unit_id', $unit->id)->where('status', 'pending')->orderBy('icv')->orderBy('id')->first(['id', 'xml', 'created_at']);
                $oldest = $first ? $this->issuedAt($first) : null;
            }

            return [
                'unit' => $unit,
                'till' => trim((string) ($device->name ?? '')) !== '' ? $device->name.' ('.$unit->device_id.')' : (string) $unit->device_id,
                'last_sync' => $device?->last_seen_at,
                'pending' => (int) ($p->n ?? 0),
                'oldest' => $oldest,
                'expires' => $unit->status === 'production' ? app(ZatcaCertificates::class)->expiry((string) $unit->production_certificate) : null,
                'gaps' => (int) $chain->where('unit_id', $unit->id)->where('chain', 'gap')->sum('n'),
                'forks' => (int) $chain->where('unit_id', $unit->id)->where('chain', 'fork')->sum('n'),
            ];
        })->values();
    }

    /** When the invoice was issued: the XML's IssueDate/IssueTime (KSA time) when earlier than the upload, else the upload time. */
    private function issuedAt(PosOfflineZatcaInvoice $inv): ?CarbonImmutable
    {
        $uploaded = $inv->created_at ? CarbonImmutable::parse($inv->created_at) : null;
        if (preg_match('#<cbc:IssueDate>(\d{4}-\d{2}-\d{2})</cbc:IssueDate>.*?<cbc:IssueTime>(\d{2}:\d{2}:\d{2})#s', (string) $inv->xml, $m)) {
            try {
                $issued = CarbonImmutable::parse($m[1].' '.$m[2], 'Asia/Riyadh');
                if ($uploaded === null || $issued->lessThan($uploaded)) {
                    return $issued;
                }
            } catch (\Throwable) {
                // unreadable issue time: fall back to the upload time
            }
        }

        return $uploaded;
    }
}
