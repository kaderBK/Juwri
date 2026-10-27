<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

use Juwri\Modules\Fiscal\Contracts\FiscalAdapter;
use Juwri\Modules\Fiscal\Contracts\GuardsSubmission;
use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Contracts\FiscalDriver;
use Juwri\Modules\Fiscal\Services\FiscalProfile;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;

/**
 * TAX-RUN Z-P3: the ZATCA FiscalDriver. Maps a queued FiscalDocument onto the
 * FATOORA API:
 *   - SIMPLIFIED (B2C): report within 24h; rejection → correction (new doc,
 *     chain intact); warnings surfaced but accepted.
 *   - STANDARD (B2B catering): clearance — the cleared (ZATCA-stamped) XML is
 *     stored and only then printable/emailable (clear-before-print).
 * It never recomputes order money — it submits the already-built payload.
 *
 * J25-8: the country's FiscalAdapter (FiscalDriver = the deprecated alias name, kept for BC).
 */
class ZatcaDriver implements FiscalAdapter, FiscalDriver, GuardsSubmission
{
    public function __construct(private ZatcaClient $client) {}

    public function country(): string
    {
        return 'SA';
    }

    public function mode(): string
    {
        // Per-document: simplified reports, standard clears. Reported at the
        // profile level as report_after_print (the common B2C case).
        return FiscalProfile::MODE_REPORT;
    }

    public function submit(FiscalDocument $document): array
    {
        [$cert, $secret] = $this->credentials($document);
        $payload = ['xml' => $document->payload, 'uuid' => $document->uuid, 'hash' => $document->hash];

        // P6-176: a note of a standard invoice is itself standard (0100000) and is cleared too
        if ($document->isStandardB2b()) {
            $res = $this->client->clearInvoice($cert, $secret, $payload);

            return [
                'status' => $res['status'],
                'response' => $res['response'],
                'cleared_payload' => $res['cleared_xml'] ?? null,
            ];
        }

        // simplified / credit_note → report
        $res = $this->client->reportInvoice($cert, $secret, $payload);

        return [
            'status' => $res['status'],
            'response' => $res['response'],
        ];
    }

    /** @return array{reason: string, message: string}|null */
    public function holdReason(FiscalDocument $document): ?array
    {
        try {
            $this->credentials($document);
        } catch (ZatcaSubmissionHeld $e) {
            return ['reason' => $e->reason, 'message' => $e->getMessage()];
        }

        return null;
    }

    /**
     * The device's production CSID and secret. Live invoices are only ever sent with a production certificate that
     * is in date and was issued on the gateway this server talks to (ZATCA_ENV); anything else throws
     * {@see ZatcaSubmissionHeld} before any HTTP call (before: a compliance device sent live invoices with its
     * compliance certificate, a missing certificate sent empty credentials, an expired one and one from the other
     * gateway were sent anyway and failed at ZATCA).
     *
     * @return array{0: string, 1: string}
     */
    private function credentials(FiscalDocument $document): array
    {
        $device = FiscalDevice::where('store_id', $document->store_id)
            ->where('device_key', $document->device_key)
            ->first();
        $who = "store {$document->store_id} / device {$document->device_key}";

        if (! $device || ! $device->isProduction()) {
            throw new ZatcaSubmissionHeld('not_production', "ZATCA {$who} is not in production (step '".($device->status ?? 'none')."'): invoices are held, not sent with a compliance certificate. Finish onboarding.");
        }
        $env = (string) config('services.zatca.environment', 'sandbox');
        $onboardedOn = $device->meta['environment'] ?? null;
        if (is_string($onboardedOn) && $onboardedOn !== '' && $onboardedOn !== $env) {
            throw new ZatcaSubmissionHeld('env_mismatch', "ZATCA {$who} was onboarded on the {$onboardedOn} gateway but ZATCA_ENV is {$env}: invoices are held. Onboard the device again on {$env}.");
        }
        $cert = FiscalCertificate::resolve($document->store_id, 'pcsid', $document->device_key);
        $pem = (string) ($cert->pem ?? '');
        $secret = (string) ($cert->meta['secret'] ?? '');
        if (! $cert || $pem === '' || $secret === '') {
            throw new ZatcaSubmissionHeld('not_onboarded', "ZATCA {$who} has no production certificate: device not onboarded, invoices are held. Onboard the device again.");
        }
        if ($cert->isExpired()) {
            throw new ZatcaSubmissionHeld('cert_expired', "ZATCA {$who}: the production certificate expired on ".$cert->expires_at?->toDateString().': invoices are held. Renew the certificate (Owner > Fiscal > Certificate renewal).');
        }

        return [$pem, $secret];
    }
}
