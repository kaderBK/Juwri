<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Illuminate\Support\Facades\DB;
use Juwri\Modules\Fiscal\Jobs\SubmitFiscalDocumentJob;
use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;

/**
 * TAX-RUN Z-P2: per-device ZATCA onboarding orchestration.
 *
 * Flow (FATOORA): CSR + OTP → Compliance CSID (CCSID) → run 6 compliance sample
 * documents → Production CSID (PCSID) → device is production. Keys/certs land in
 * the encrypted FiscalCertificate vault; the device advances through its status
 * machine (new → compliance → production). OTP is single-use / time-boxed; an
 * expired or malformed OTP fails the compliance step without advancing state.
 */
class ZatcaOnboarding
{
    public function __construct(
        private ZatcaClient $client,
        private ZatcaCrypto $crypto,
    ) {}

    /** Create (or fetch) the device row for a branch EGS unit. */
    public function ensureDevice(int $storeId, string $deviceKey, ?int $branchId = null, array $meta = []): FiscalDevice
    {
        return FiscalDevice::firstOrCreate(
            ['store_id' => $storeId, 'device_key' => $deviceKey],
            ['country' => 'SA', 'branch_id' => $branchId, 'status' => 'new', 'meta' => $meta],
        );
    }

    /**
     * Step 1: generate keypair + CSR, request the compliance CSID with the OTP,
     * store the private key + CCSID. Advances new → compliance.
     *
     * @param  array<string,string>  $egs
     */
    public function requestComplianceCsid(FiscalDevice $device, string $otp, array $egs): FiscalCertificate
    {
        $keys = $this->crypto->generateKeyPair();
        $csr = $this->crypto->generateCsr($keys['private'], $egs + ['vat' => $egs['vat'] ?? '']);

        // Fails (throws) on invalid/expired OTP — device stays 'new'.
        $ccsid = $this->client->complianceCsid(base64_encode($csr), $otp);

        // A device already in production keeps signing and sending with its current key and production certificate
        // until a NEW production certificate is stored (before: re-onboarding demoted it to 'compliance' at once, so
        // if the compliance samples then failed its valid production certificate sat unused). The new key waits as
        // pending (inactive) so live invoices are never signed with a key that does not match the live certificate.
        $live = $device->isProduction();

        // Private key stored encrypted, separate from the cert.
        $key = FiscalCertificate::create([
            'store_id' => $device->store_id, 'device_key' => $device->device_key,
            'kind' => 'private_key', 'pem' => $keys['private'], 'active' => ! $live,
            'meta' => ['public' => $keys['public']] + ($live ? ['pending' => true] : []),
        ]);

        $cert = FiscalCertificate::create([
            'store_id' => $device->store_id, 'device_key' => $device->device_key,
            'kind' => 'ccsid', 'pem' => $ccsid['certificate'], 'active' => true, 'expires_at' => ZatcaCertificate::expiry($ccsid['certificate']),
            'meta' => ['secret' => $ccsid['secret'], 'request_id' => $ccsid['request_id']],
        ]);

        $onboarding = [
            'environment' => (string) config('services.zatca.environment', 'sandbox'),
            'egs' => ['common_name' => $egs['common_name'] ?? '', 'organization' => $egs['organization'] ?? '', 'vat' => $egs['vat'] ?? ''],
        ];
        if ($live) {
            $device->update(['meta' => array_merge($device->meta ?? [], [
                'ccsid_request_id' => $ccsid['request_id'],
                'pending' => $onboarding + ['key_id' => $key->id, 'ccsid_id' => $cert->id],
            ])]);
        } else {
            $device->update(['status' => 'compliance', 'meta' => array_merge($device->meta ?? [], ['ccsid_request_id' => $ccsid['request_id']], $onboarding)]);
        }

        return $cert;
    }

    /**
     * Step 2: run the 6 mandated compliance sample documents. All must PASS to
     * proceed. Returns true when compliance is satisfied.
     *
     * @param  array<int,array>  $sampleDocuments
     */
    public function runComplianceChecks(FiscalDevice $device, array $sampleDocuments): bool
    {
        $ccsid = $this->onboardingCert($device, 'ccsid');
        if (! $ccsid) {
            throw new \RuntimeException('No compliance CSID for device.');
        }
        $secret = $ccsid->meta['secret'] ?? '';

        foreach ($sampleDocuments as $doc) {
            $res = $this->client->complianceCheck($ccsid->pem, $secret, $doc);
            if (($res['status'] ?? '') !== 'PASS') {
                return false;
            }
        }

        return true;
    }

    /**
     * Step 3: request the production CSID and mark the device production. The
     * receipt QR auto-upgrades to 9 tags once the device reaches this state.
     */
    public function requestProductionCsid(FiscalDevice $device): FiscalCertificate
    {
        $ccsid = $this->onboardingCert($device, 'ccsid');
        if (! $ccsid) {
            throw new \RuntimeException('No compliance CSID for device.');
        }

        $pcsid = $this->client->productionCsid(
            $device->meta['ccsid_request_id'] ?? '',
            $ccsid->pem,
            $ccsid->meta['secret'] ?? '',
        );

        $cert = DB::transaction(function () use ($device, $pcsid) {
            $meta = (array) ($device->meta ?? []);
            $pending = is_array($meta['pending'] ?? null) ? $meta['pending'] : null;
            unset($meta['pending'], $meta['held']);
            if ($pending !== null) {
                // Swap in the new key + certificate together; the old pair stops only now.
                FiscalCertificate::where('store_id', $device->store_id)->where('device_key', $device->device_key)
                    ->whereIn('kind', ['pcsid', 'private_key'])->where('active', true)->update(['active' => false]);
                FiscalCertificate::where('store_id', $device->store_id)->where('device_key', $device->device_key)
                    ->whereKey($pending['key_id'] ?? 0)->update(['active' => true]);
                $meta['environment'] = $pending['environment'] ?? ($meta['environment'] ?? null);
                $meta['egs'] = $pending['egs'] ?? ($meta['egs'] ?? []);
            }
            $cert = FiscalCertificate::create([
                'store_id' => $device->store_id, 'device_key' => $device->device_key,
                'kind' => 'pcsid', 'pem' => $pcsid['certificate'], 'active' => true, 'expires_at' => ZatcaCertificate::expiry($pcsid['certificate']),
                'meta' => ['secret' => $pcsid['secret'], 'request_id' => $pcsid['request_id']],
            ]);
            $device->update(['status' => 'production', 'meta' => array_merge($meta, ['production_at' => now()->toIso8601String()])]);

            return $cert;
        });
        $this->releaseHeld($device);

        return $cert;
    }

    /** The key / compliance certificate of the onboarding in progress: the pending pair of a re-onboarding production device, else the active one. */
    private function onboardingCert(FiscalDevice $device, string $kind): ?FiscalCertificate
    {
        $id = $device->meta['pending'][$kind === 'ccsid' ? 'ccsid_id' : 'key_id'] ?? null;
        if ($id !== null) {
            $pending = FiscalCertificate::where('store_id', $device->store_id)->where('device_key', $device->device_key)
                ->where('kind', $kind)->whereKey($id)->first();
            if ($pending) {
                return $pending;
            }
        }

        return FiscalCertificate::resolve($device->store_id, $kind, $device->device_key);
    }

    /** Documents held while the device had no usable certificate are sent now that it has one. */
    private function releaseHeld(FiscalDevice $device): void
    {
        $device->refresh();
        if (isset($device->meta['held'])) {
            $meta = $device->meta;
            unset($meta['held']);
            $device->update(['meta' => $meta]);
        }
        FiscalDocument::where('store_id', $device->store_id)->where('device_key', $device->device_key)
            ->where('country', 'SA')->where('status', 'queued')->orderBy('counter')->pluck('id')
            ->each(fn ($id) => SubmitFiscalDocumentJob::dispatch((int) $id));
    }

    /**
     * The six sample documents for the compliance check, built and stamped with this device's own key and compliance
     * certificate. Throws when the device has no key yet (compliance CSID not requested).
     *
     * @param  array<string,string>  $sellerAddress  P6-176: the store's national address (the standard samples need it)
     * @return list<array<string,string>>
     */
    public function complianceSamples(FiscalDevice $device, string $sellerName, string $vat, array $sellerAddress = [], ?string $sellerCr = null): array
    {
        $key = $this->onboardingCert($device, 'private_key');
        $ccsid = $this->onboardingCert($device, 'ccsid');
        if (! $key || ! $ccsid) {
            throw new \RuntimeException('No device key or compliance CSID for device.');
        }

        return (new ZatcaComplianceSamples($this->crypto))->build($sellerName, $vat,
            ['private' => $key->pem, 'public' => (string) ($key->meta['public'] ?? '')], ZatcaCertificate::body($ccsid->pem),
            $sellerAddress, $sellerCr);
    }

    /**
     * Renew the production certificate before it expires: new key + CSR, a fresh one-time code from the Fatoora portal,
     * authenticated by the CURRENT PCSID. The old certificate and key stay active until the new ones are stored, so a
     * failed renewal (bad code, network) never leaves the device without a working certificate.
     */
    /** @param  array<string, string>  $egs */
    public function renewProductionCsid(FiscalDevice $device, string $otp, array $egs): FiscalCertificate
    {
        $current = FiscalCertificate::resolve($device->store_id, 'pcsid', $device->device_key);
        if (! $current) {
            throw new \RuntimeException('No production CSID to renew for this device.');
        }
        $keys = $this->crypto->generateKeyPair();
        $csr = $this->crypto->generateCsr($keys['private'], $egs + ['vat' => $egs['vat'] ?? '']);

        $new = $this->client->renewProductionCsid($current->pem, $current->meta['secret'] ?? '', base64_encode($csr), $otp);

        $cert = DB::transaction(function () use ($device, $keys, $new) {
            FiscalCertificate::where('store_id', $device->store_id)->where('device_key', $device->device_key)
                ->whereIn('kind', ['pcsid', 'private_key'])->where('active', true)->update(['active' => false]);
            FiscalCertificate::create([
                'store_id' => $device->store_id, 'device_key' => $device->device_key,
                'kind' => 'private_key', 'pem' => $keys['private'], 'active' => true, 'meta' => ['public' => $keys['public']],
            ]);
            $cert = FiscalCertificate::create([
                'store_id' => $device->store_id, 'device_key' => $device->device_key,
                'kind' => 'pcsid', 'pem' => $new['certificate'], 'active' => true, 'expires_at' => ZatcaCertificate::expiry($new['certificate']),
                'meta' => ['secret' => $new['secret'], 'request_id' => $new['request_id'], 'renewed_at' => now()->toIso8601String()],
            ]);
            $device->update(['meta' => array_merge($device->meta ?? [], ['renewed_at' => now()->toIso8601String()])]);

            return $cert;
        });
        $this->releaseHeld($device);

        return $cert;
    }
}
