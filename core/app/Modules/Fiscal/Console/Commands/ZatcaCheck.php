<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Console\Commands;

use Illuminate\Console\Command;
use Juwri\Core\Services\AlertDispatcher;
use Juwri\Modules\Fiscal\Contracts\ZatcaOfflineTills;
use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;

/**
 * RB-11: is this install ready to report to ZATCA for real? Read-only, no network.
 *   FAIL  = invoices would be wrong or rejected now (exit 1; the daily schedule alerts on it)
 *   WARN  = fix soon (exit 1 only with --strict)
 * Run it before the go-live switch (ZATCA_LIVE=true, ZATCA_ENV=production) and after; see
 * ZATCA-PRODUCTION-STEPS.md.
 * P6-94: also checks every offline POS till that is its own EGS unit (certificate, pending age, ICV/PIH chain, last sync);
 * with --alert (the daily schedule) the FAIL rows, with store and till, go to the fiscal alert channel.
 */
class ZatcaCheck extends Command
{
    protected $signature = 'fiscal:zatca-check {--store= : only this store id} {--strict : warnings also fail} {--alert : send FAIL rows to the fiscal alert channel}';

    protected $description = 'Check ZATCA config, device certificates, expiry and tax details (no network calls)';

    /** @var list<array{string,string,string}> */
    private array $rows = [];

    public function handle(): int
    {
        $this->rows = []; // the same instance can run twice in one process (Artisan::call, tests)
        $this->config();
        $devices = FiscalDevice::withoutGlobalScopes()->where('country', 'SA')
            ->when($this->option('store'), fn ($q, $id) => $q->where('store_id', (int) $id))->get();
        if ($devices->isEmpty()) {
            $this->add('WARN', 'devices', 'No ZATCA device onboarded yet (Owner > Fiscal > ZATCA device onboarding).');
        }
        foreach ($devices as $d) {
            $this->device($d);
        }
        $this->documents();
        $this->offlineTills();

        $this->table(['Level', 'Check', 'Detail'], $this->rows);
        $fail = collect($this->rows)->contains(fn ($r) => $r[0] === 'FAIL');
        $warn = collect($this->rows)->contains(fn ($r) => $r[0] === 'WARN');

        if ($fail && $this->option('alert')) {
            $lines = collect($this->rows)->filter(fn ($r) => $r[0] === 'FAIL')->map(fn ($r) => $r[1].': '.$r[2])->values();
            AlertDispatcher::send('fiscal', '[zatca-check] '.$lines->count().' FAIL: '.mb_substr($lines->implode(' | '), 0, 3500));
        }

        return $fail || ($warn && $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }

    private function offlineTills(): void
    {
        if (! app()->bound(ZatcaOfflineTills::class)) {
            return;
        }
        $store = $this->option('store') ? (int) $this->option('store') : null;
        foreach (app(ZatcaOfflineTills::class)->findings($store) as $f) {
            $this->add($f['level'], "store {$f['store_id']} / till {$f['till']}", $f['detail']);
        }
    }

    private function add(string $level, string $check, string $detail): void
    {
        $this->rows[] = [$level, $check, $detail];
    }

    private function config(): void
    {
        $env = (string) config('services.zatca.environment');
        $live = (bool) config('services.zatca.live');
        if (! in_array($env, ['sandbox', 'simulation', 'production'], true) && ! config('services.zatca.base_url')) {
            $this->add('FAIL', 'ZATCA_ENV', "'{$env}' is not sandbox, simulation or production.");
        }
        if ($env === 'production' && ! $live) {
            $this->add('FAIL', 'ZATCA_LIVE', 'ZATCA_ENV=production but ZATCA_LIVE is off: the demo client would answer and no invoice would reach ZATCA.');
        } elseif ($live && $env !== 'production') {
            $this->add('WARN', 'ZATCA_ENV', "Live calls go to the {$env} gateway: no legal effect. Set ZATCA_ENV=production for go-live.");
        } elseif (! $live) {
            $this->add('WARN', 'ZATCA_LIVE', 'ZATCA_LIVE is off: the demo client answers (fine for development only).');
        } else {
            $this->add('OK', 'config', "Live, environment {$env}.");
        }
        if (config('services.zatca.base_url')) {
            $this->add('WARN', 'ZATCA_BASE_URL', 'A custom base URL overrides ZATCA_ENV: '.config('services.zatca.base_url'));
        }
        if (config('fiscal.adapters.SA.enabled') === false) {
            $this->add('FAIL', 'fiscal.SA', 'Saudi fiscalization is switched off in config/fiscal.php.');
        }
    }

    private function device(FiscalDevice $d): void
    {
        $name = "store {$d->store_id} / {$d->device_key}";
        $meta = (array) ($d->meta ?? []);
        $env = (string) config('services.zatca.environment');

        $vat = (string) store_options($d->store_id, 'fiscal_vat_number');
        if (! preg_match('/^3\d{13}3$/', $vat)) {
            $this->add('FAIL', $name, 'Store VAT number must be 15 digits starting and ending with 3 (Store settings > Tax & Compliance).');
        }
        if (trim((string) store_options($d->store_id, 'fiscal_legal_name')) === '') {
            $this->add('WARN', $name, 'No legal name set; the store title is used as the seller name.');
        }
        if (! empty($meta['hosted'])) {
            $this->add('OK', $name, 'Signs through the juwri hosted service; the certificate is kept there.');

            return;
        }
        if (! $d->isProduction()) {
            $this->add($env === 'production' ? 'FAIL' : 'WARN', $name, "Device is in step '{$d->status}', not production yet.");

            return;
        }
        if (isset($meta['environment']) && $meta['environment'] !== $env) {
            $this->add('FAIL', $name, "Onboarded on the {$meta['environment']} gateway but ZATCA_ENV is {$env}: its certificate is not valid there. Onboard again.");
        }
        $key = FiscalCertificate::resolve($d->store_id, 'private_key', $d->device_key);
        $pcsid = FiscalCertificate::resolve($d->store_id, 'pcsid', $d->device_key);
        if (! $key || ! $pcsid) {
            $this->add('FAIL', $name, 'Production device without an active key or production certificate: invoices cannot be signed.');

            return;
        }
        if ($pcsid->expires_at === null) {
            $this->add('WARN', $name, 'Certificate expiry unknown (not readable); check it in the Fatoora portal.');

            return;
        }
        $days = (int) floor(now()->diffInDays($pcsid->expires_at, false));
        $warn = (int) config('services.zatca.renew_warn_days', 45);
        if ($days < 0) {
            $this->add('FAIL', $name, 'Production certificate EXPIRED '.abs($days).' day(s) ago. Renew now (Owner > Fiscal > Certificate renewal).');
        } elseif ($days <= 14) {
            $this->add('FAIL', $name, "Production certificate expires in {$days} day(s). Renew now.");
        } elseif ($days <= $warn) {
            $this->add('WARN', $name, "Production certificate expires in {$days} day(s). Renew soon.");
        } else {
            $this->add('OK', $name, "Production certificate valid for {$days} more day(s).");
        }
    }

    private function documents(): void
    {
        $rejected = FiscalDocument::withoutGlobalScopes()->where('country', 'SA')->where('status', 'rejected')
            ->where('updated_at', '>=', now()->subDays(7))
            ->when($this->option('store'), fn ($q, $id) => $q->where('store_id', (int) $id))->count();
        if ($rejected > 0) {
            $this->add('WARN', 'documents', "{$rejected} ZATCA document(s) rejected in the last 7 days (Owner > Fiscal: fix and resubmit).");
        }
    }
}
