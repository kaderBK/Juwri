<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Http\Controllers\User;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Juwri\Core\Features\PlatformFeatures;
use Juwri\Core\Http\Controllers\Concerns\ResolvesCurrentStore;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Modules\Fiscal\Contracts\ZatcaOfflineTills;
use Juwri\Modules\Fiscal\Jobs\SubmitFiscalDocumentJob;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\FiscalRegistry;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaComplianceSamples;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaOnboarding;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaParties;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaPartiesMissing;

/**
 * TAX-RUN Z-P4 (+ J-P2/E-P2): the owner Fiscalization console. Shows the
 * restaurant's fiscal profile + driver status, the document log (shared across
 * all drivers), the ZATCA onboarding wizard, and the credentials forms for the
 * clearance drivers. Tenant-scoped via ResolvesCurrentStore; behind the
 * `fiscalization` master kill-switch (route middleware) plus the per-country
 * switch (checked here).
 */
class FiscalController extends Controller
{
    use ResolvesCurrentStore;

    /** Guard: the per-country switch must also be on for a country's surfaces. */
    private function countryEnabled(?string $country): bool
    {
        $cc = strtolower((string) $country);

        return $cc !== '' && PlatformFeatures::active('fiscal_'.$cc);
    }

    public function index(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $country = (string) store_options($rid, 'fiscal_country');
        $profile = FiscalRegistry::profile($country);

        $documents = FiscalDocument::where('store_id', $rid)
            ->latest('id')->limit(50)->get();
        $devices = FiscalDevice::where('store_id', $rid)->get();

        return view('owner.accounting.fiscal', [
            'rid' => $rid,
            'country' => $country,
            'profile' => $profile,
            'countryEnabled' => $this->countryEnabled($country),
            'documents' => $documents,
            'devices' => $devices,
            // P6-94: offline POS tills that sign as their own ZATCA unit (Pos module; empty when absent)
            'offlineTills' => $country === 'SA' && app()->bound(ZatcaOfflineTills::class)
                ? app(ZatcaOfflineTills::class)->status($rid) : [],
            'stores' => Store::whereIn('id', $this->storeIds())->get(['id', 'title']),
        ]);
    }

    public function resubmit(Request $request, int $id)
    {
        $rid = $this->currentRestaurantId($request);
        $doc = FiscalDocument::where('store_id', $rid)->findOrFail($id); // cross-tenant → 404

        if (in_array($doc->status, ['accepted', 'superseded'], true)) {
            return back()->with('error', ___('Document already finalized.'));
        }

        $doc->transitionTo('queued', ['queued_at' => now()]);
        SubmitFiscalDocumentJob::dispatch($doc->id);

        return back()->with('success', ___('Document re-queued for submission.'));
    }

    /**
     * Save BYOK driver credentials into the encrypted Credential vault (feature =
     * authority name). Never plaintext settings. JoFotara: Client ID / Secret /
     * Activity Number. ETA: RIN / client id / secret.
     */
    public function saveCredentials(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $country = strtolower((string) store_options($rid, 'fiscal_country'));
        abort_unless($this->countryEnabled($country), 404);

        $feature = match ($country) {
            'jo' => 'jofotara',
            'eg' => 'eta',
            default => abort(404),
        };

        $data = $request->validate([
            'secrets' => 'required|array',
            'sandbox' => 'nullable|boolean',
        ]);

        $secrets = array_map('strval', $data['secrets']);
        $secrets['sandbox'] = (bool) ($data['sandbox'] ?? true) ? '1' : '0';

        Credential::updateOrCreate(
            ['store_id' => $rid, 'feature' => $feature, 'provider' => $feature],
            ['secrets' => $secrets, 'active' => true],
        );

        return back()->with('success', ___('Credentials saved.'));
    }

    /** Register an ETA POS device (serial per branch) for the current restaurant. */
    public function registerEtaDevice(Request $request)
    {
        $rid = $this->currentRestaurantId($request);
        $country = (string) store_options($rid, 'fiscal_country');
        abort_unless($country === 'EG' && $this->countryEnabled('eg'), 404);

        $data = $request->validate([
            'serial' => 'required|string|max:120',
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $rid)],
            'branch_code' => 'nullable|string|max:40',
        ]);

        FiscalDevice::updateOrCreate(
            ['store_id' => $rid, 'device_key' => $data['serial']],
            ['country' => 'EG', 'branch_id' => $data['branch_id'] ?? null, 'serial' => $data['serial'],
                'status' => 'registered', 'meta' => ['branchCode' => $data['branch_code'] ?? '0']],
        );
        $this->etaWebhookToken($rid); // ensure a per-tenant callback secret exists

        return back()->with('success', ___('POS device registered.'));
    }

    /**
     * Per-restaurant ETA callback secret, kept in the encrypted Credential vault.
     * Embedded in the callback URL we register with ETA so the public webhook can
     * authenticate the caller and scope the lookup to one tenant.
     */
    private function etaWebhookToken(int $rid): string
    {
        $cred = Credential::firstOrNew(['feature' => 'eta_webhook', 'store_id' => $rid]);
        if (empty($cred->secrets['token'])) {
            $cred->provider = 'eta';
            $cred->active = true;
            $cred->secrets = ['token' => bin2hex(random_bytes(20))];
            $cred->save();
        }

        return $cred->secrets['token'];
    }

    /**
     * ETA async validation callback (public webhook). ETA notifies the taxpayer of
     * a document's final valid/invalid state; we flip the matching document. The
     * caller is authenticated by a per-tenant secret in the URL (constant-time
     * compare) and the document lookup is scoped to that tenant — an unknown token
     * or cross-tenant uuid → 404, no state change.
     */
    public function etaCallback(Request $request, string $token)
    {
        $cred = Credential::where('feature', 'eta_webhook')
            ->where('active', true)
            ->get()
            ->first(fn ($c) => $token !== '' && hash_equals((string) ($c->secrets['token'] ?? ''), $token));
        if (! $cred) {
            return response()->json(['ok' => false], 404);
        }

        $uuid = (string) $request->input('uuid');
        $status = strtolower((string) $request->input('status')); // valid|invalid
        $doc = FiscalDocument::where('uuid', $uuid)
            ->where('store_id', $cred->store_id)
            ->first();
        if (! $doc) {
            return response()->json(['ok' => false], 404);
        }

        if ($status === 'valid' && $doc->canTransitionTo('accepted')) {
            $doc->transitionTo('accepted', ['authority_response' => array_merge($doc->authority_response ?? [], ['eta_status' => 'Valid'])]);
        } elseif ($status === 'invalid' && $doc->canTransitionTo('rejected')) {
            $doc->transitionTo('rejected', ['authority_response' => array_merge($doc->authority_response ?? [], ['eta_status' => 'Invalid'])]);
        }

        return response()->json(['ok' => true]);
    }

    // ── ZATCA onboarding wizard ───────────────────────────────────────────────

    public function zatcaOnboard(Request $request, ZatcaClient $client, ZatcaCrypto $crypto)
    {
        $rid = $this->currentRestaurantId($request);
        $country = (string) store_options($rid, 'fiscal_country');
        abort_unless($country === 'SA' && $this->countryEnabled('SA'), 404);

        $data = $request->validate([
            'device_key' => 'required|string|max:100',
            // P4-01 IDOR: the device's branch must be one of THIS store's branches (before: any id was stored)
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('store_id', $rid)],
            'otp' => 'required|string|min:6|max:12',
        ]);

        $store = Store::findOrFail($rid);
        $egs = [
            'common_name' => $data['device_key'],
            'organization' => (string) (store_options($rid, 'fiscal_legal_name') ?: $store->title),
            'organization_unit' => (string) store_options($rid, 'fiscal_vat_number'),
            'vat' => (string) store_options($rid, 'fiscal_vat_number'),
            'country' => 'SA',
        ];

        // P6-176: the standard compliance samples carry the seller's national address — check it before the one-time code
        // is spent (a sample that cannot be built would burn the OTP)
        $sellerAddress = ZatcaParties::sellerAddress(store_options($rid));
        $gaps = ZatcaParties::standardGaps($egs['organization'], $egs['vat'], $sellerAddress, ZatcaComplianceSamples::SAMPLE_BUYER);
        if ($gaps !== []) {
            return back()->with('error', ___('Onboarding failed:').' '.ZatcaPartiesMissing::describe($gaps));
        }

        // RB-11: with ZATCA_ENV=production the demo (fake) client must never answer: it would mint fake certificates.
        if (config('services.zatca.environment') === 'production' && ! config('services.zatca.live')) {
            return back()->with('error', ___('Onboarding failed:').' '.___('The live ZATCA connection is off (ZATCA_LIVE).'));
        }

        $svc = new ZatcaOnboarding($client, $crypto);
        $device = $svc->ensureDevice($rid, $data['device_key'], $data['branch_id'] ?? null);

        try {
            $svc->requestComplianceCsid($device, $data['otp'], $egs);
            // Run the six real sample documents (standard + simplified, invoice / credit / debit), then request the PCSID.
            $samples = $svc->complianceSamples($device->fresh(), $egs['organization'], $egs['vat'], $sellerAddress,
                (string) store_options($rid, 'fiscal_cr_number') ?: null);
            $ok = $svc->runComplianceChecks($device->fresh(), $samples);
            if (! $ok) {
                return back()->with('error', ___('Onboarding failed:').' '.___('ZATCA did not accept the compliance sample documents. The device stays in the compliance step; fix the tax details and onboard again with a new code.'));
            }
            $svc->requestProductionCsid($device->fresh());
        } catch (\Throwable $e) {
            return back()->with('error', ___('Onboarding failed:').' '.$e->getMessage());
        }

        return back()->with('success', ___('Device onboarded.').' '.$device->fresh()->status);
    }

    /** RB-11: renew the production certificate with a new one-time code from the Fatoora portal. */
    public function zatcaRenew(Request $request, ZatcaClient $client, ZatcaCrypto $crypto): RedirectResponse
    {
        $rid = $this->currentRestaurantId($request);
        abort_unless((string) store_options($rid, 'fiscal_country') === 'SA' && $this->countryEnabled('SA'), 404);
        $data = $request->validate([
            'device_key' => ['required', 'string', 'max:100', Rule::exists('fiscal_devices', 'device_key')->where('store_id', $rid)],
            'otp' => 'required|string|min:6|max:12',
        ]);
        $device = FiscalDevice::where('store_id', $rid)->where('device_key', $data['device_key'])->firstOrFail();
        $meta = (array) ($device->meta ?? []);
        $egs = ['common_name' => (string) ($meta['egs']['common_name'] ?? $device->device_key), 'organization' => (string) ($meta['egs']['organization'] ?? ''),
            'organization_unit' => (string) ($meta['egs']['vat'] ?? ''), 'vat' => (string) ($meta['egs']['vat'] ?? ''), 'country' => 'SA'];
        try {
            (new ZatcaOnboarding($client, $crypto))->renewProductionCsid($device, $data['otp'], $egs);
        } catch (\Throwable $e) {
            return back()->with('error', ___('Renewal failed:').' '.___($e->getMessage())); // P6-87: the fixed renewal errors are translated; ZATCA's own texts stay as sent
        }

        return back()->with('success', ___('Certificate renewed.'));
    }
}
