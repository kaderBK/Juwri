<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Modules\Fiscal\Contracts\GuardsSubmission;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\FiscalDriverManager;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaSubmissionHeld;

/**
 * TAX-RUN F0-P2: base submission job for reporting/clearing a fiscal document to
 * its authority. Follows the DeliverWebhookJob idiom — bounded retries with
 * exponential backoff, idempotent, and audit-friendly (writes authority_response
 * + advances the status machine). Per-driver behaviour lives in the resolved
 * FiscalDriver; this job owns the queue mechanics and the status transitions.
 *
 * Idempotency: an already-accepted (or superseded) document is a no-op, so a
 * replayed job can never double-submit or corrupt the chain.
 */
class SubmitFiscalDocumentJob implements ShouldQueue
{
    use Queueable;

    /** 1 initial + 4 retries, matching the webhook idiom. */
    public int $tries = 5;

    /** P6-64: below queue retry_after. */
    public int $timeout = 120;

    public function __construct(public int $documentId) {}

    /** Exponential backoff (seconds): ~1m, 5m, 15m, 1h. */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(FiscalDriverManager $drivers): void
    {
        $doc = FiscalDocument::find($this->documentId);
        if (! $doc) {
            return;
        }

        // Idempotent: terminal-accepted / superseded docs are never re-sent.
        // P6-139: and only a document still owed to the authority is sent. A rejected one waits for its correction
        // (a new document); before, a job re-run after a kill past the rejection re-sent it.
        if (! in_array($doc->status, ['draft', 'queued', 'submitted'], true)) {
            return;
        }

        $driver = $drivers->for($doc->country);
        if (! $driver) {
            // No live driver for this country — nothing to submit. The document
            // stays queued and the SLA watchdog surfaces it if it lingers.
            return;
        }

        // The adapter may refuse up front (no usable production certificate, wrong gateway): the document stays queued,
        // nothing is sent, one alert per device and reason, and no retry (a retry cannot fix a certificate).
        if ($driver instanceof GuardsSubmission && ($hold = $driver->holdReason($doc)) !== null) {
            if ($doc->status === 'draft') {
                $doc->transitionTo('queued', ['queued_at' => now()]);
            }
            $this->hold($doc, $hold['reason'], $hold['message']);

            return;
        }

        // Move draft → queued → submitted before the round-trip so a crash mid
        // call leaves an auditable "submitted" state (never a lost document).
        if ($doc->status === 'draft') {
            $doc->transitionTo('queued', ['queued_at' => now()]);
        }
        $doc->transitionTo('submitted');

        try {
            /** @var array<string, mixed> $result an adapter from outside the module may still omit the status */
            $result = $driver->submit($doc);
        } catch (ZatcaSubmissionHeld $e) {
            $this->hold($doc, $e->reason, $e->getMessage()); // changed between the check and the send: still never retried

            return;
        }
        $this->clearHold($doc);
        $status = $result['status'] ?? 'submitted';

        // Merge (not replace) so QR artefacts already stored by the driver's
        // document builder (ZATCA tlv/tags, ISTD QR, lookup URL) survive the
        // authority response being recorded.
        $update = ['authority_response' => array_merge($doc->authority_response ?? [], $result['response'] ?? [])];
        if (! empty($result['cleared_payload'])) {
            $update['payload'] = $result['cleared_payload'];
        }

        if (! empty($result['hash'])) {
            FiscalChain::seal($doc, $result['hash']);
        }

        if ($status === 'accepted') {
            $doc->transitionTo('accepted', $update);
        } elseif ($status === 'rejected') {
            $doc->transitionTo('rejected', $update);
        } else {
            $doc->update($update); // stays submitted, async validation pending
        }
    }

    /** Mark the device as holding invoices (shown on the owner's fiscal page) and alert the platform once per device and reason. */
    private function hold(FiscalDocument $doc, string $reason, string $message): void
    {
        $device = FiscalDevice::where('store_id', $doc->store_id)->where('device_key', $doc->device_key)->first();
        if ($device && (($device->meta['held']['reason'] ?? null) !== $reason)) {
            $device->update(['meta' => array_merge($device->meta ?? [], ['held' => ['reason' => $reason, 'at' => now()->toIso8601String()]])]);
        }
        Log::warning('fiscal.submit.held', ['document_id' => $doc->id, 'store_id' => $doc->store_id, 'reason' => $reason]);
        AlertDispatcher::send('fiscal', '[fiscal-submit-held] '.$message); // device-level text: AlertDispatcher keeps one open alert per day
    }

    private function clearHold(FiscalDocument $doc): void
    {
        $device = FiscalDevice::where('store_id', $doc->store_id)->where('device_key', $doc->device_key)->first();
        if ($device && isset($device->meta['held'])) {
            $meta = $device->meta;
            unset($meta['held']);
            $device->update(['meta' => $meta]);
        }
    }

    /** RB-11: all retries used up (network down, bad credentials, ZATCA 5xx): tell the platform, the document stays 'submitted' for resubmit. */
    public function failed(\Throwable $e): void
    {
        try {
            $doc = FiscalDocument::find($this->documentId);
            Log::error('fiscal.submit.failed', ['document_id' => $this->documentId, 'store_id' => $doc?->store_id, 'country' => $doc?->country, 'error' => mb_substr($e->getMessage(), 0, 300)]);
            AlertDispatcher::send('fiscal', '[fiscal-submit-failed] document '.$this->documentId.' ('.($doc->country ?? '?').'): '.mb_substr($e->getMessage(), 0, 200));
        } catch (\Throwable $ignored) {
            // alerting must never break the queue
        }
    }
}
