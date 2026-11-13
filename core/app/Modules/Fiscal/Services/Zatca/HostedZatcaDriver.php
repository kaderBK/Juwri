<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Juwri\Core\Models\Store;
use Juwri\Core\Services\HostedServices\HostedServiceClient;
use Juwri\Modules\Fiscal\Contracts\FiscalAdapter;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalProfile;

/**
 * Self-hosted KSA stores on juwri's hosted ZATCA signing service (FISCAL_SA_SIGNING=juwri_hosted, after
 * `juwri:services:connect` and `fiscal:zatca-hosted-onboard`): the EGS unit's key lives at juwri, never here. The
 * unsigned UBL the issuer built is sent to POST api/hosted/v1/zatca/sign for the invoicing store and the EGS unit's
 * branch; juwri stamps it and reports / clears it with ZATCA. The signed XML, its hash and the 9-tag QR are stored
 * back on the document. A refusal (store past the edition's limit, unknown unit) leaves the document submitted with
 * juwri's reason — nothing is signed locally instead.
 */
class HostedZatcaDriver implements FiscalAdapter
{
    public function __construct(private readonly HostedServiceClient $client) {}

    public function country(): string
    {
        return 'SA';
    }

    public function mode(): string
    {
        return FiscalProfile::MODE_REPORT;
    }

    public function submit(FiscalDocument $document): array
    {
        $store = Store::query()->find($document->store_id);
        $device = FiscalDevice::query()->where('store_id', $document->store_id)->where('device_key', $document->device_key)->first();
        $branchId = $document->branch_id ?? $device?->branch_id;
        if ($store === null || $branchId === null) {
            return ['status' => 'submitted', 'response' => ['hosted_error' => 'branch_required']];
        }
        try {
            $res = $this->client->send('POST', '/api/hosted/v1/zatca/sign', [
                'egs_serial' => (string) $document->device_key, 'xml' => (string) $document->payload,
                'type' => in_array($document->type, ['simplified', 'standard', 'credit_note'], true) ? $document->type : 'simplified',
            ], $store, (int) $branchId);
        } catch (\Throwable) {
            return ['status' => 'submitted', 'response' => ['hosted_error' => 'unreachable']];
        }
        if (! $res->successful()) {
            return ['status' => 'submitted', 'response' => ['hosted_error' => (string) $res->json('code'), 'hosted_message' => (string) $res->json('message')]];
        }

        $core = TlvQr::decode((string) (($document->authority_response ?? [])['tlv'] ?? ''));
        $response = (array) ($res->json('zatca') ?? []);
        if (count($core) >= 5) {
            $response['tlv'] = TlvQr::nineTag(['seller' => (string) $core[1], 'vat' => (string) $core[2], 'timestamp' => (string) $core[3], 'total' => (string) $core[4], 'vat_amount' => (string) $core[5]],
                ['hash' => (string) $res->json('hash'), 'signature' => (string) $res->json('signature'), 'public_key' => (string) $res->json('public_key'), 'ca_signature' => (string) base64_decode((string) $res->json('ca_signature'), true)]); // P6-87: tag 9 when the signer returns it
            $response['tags'] = 9;
        }
        $status = (string) $res->json('status');

        return [
            'status' => in_array($status, ['accepted', 'rejected'], true) ? $status : 'submitted',
            'response' => $response,
            'cleared_payload' => (string) $res->json('xml'),
            'hash' => (string) $res->json('hash'),
        ];
    }
}
