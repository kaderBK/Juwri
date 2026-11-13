<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Console\Commands;

use Juwri\Modules\Channels\Services\Aggregators\Swiggy\SwiggyPayloadMapper;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\ZomatoPayloadMapper;
use Illuminate\Console\Command;

/**
 * PARITY-RUN follow-up — validate a REAL captured aggregator payload against the
 * driver's ASSUMED field names.
 *
 * T7 shipped Zomato/Swiggy as scaffold + BYOK (DECISION D1); their wire formats
 * are behind a partner program, so every field name in the two payload mappers
 * is an assumption reconstructed from public docs. The ledger row for this says:
 * "reconcile against a real payload once credentials land."
 *
 * There is no real payload in this environment and none can be manufactured — a
 * payload we invent would just re-validate our own assumptions. So this is the
 * enabling tool: the instant a real Zomato/Swiggy webhook body is captured
 * (from a partner account, a webhook.site capture, or certification docs), run
 *
 *   php artisan aggregator:validate-payload zomato /path/to/captured.json
 *
 * and it runs that JSON through the SAME mapper the ingest pipeline uses, and
 * reports, field by field, what resolved and what did not — turning "the field
 * names are assumed" into a pass/fail diff.
 *
 * It is read-only: it maps and reports, and creates no order. Order creation
 * still only happens through AggregatorIngestor → OrderApiService.
 *
 * The fields marked REQUIRED are the ones AggregatorIngestor cannot proceed
 * without (it would reject the webhook): the outlet id, the order id, and at
 * least one line carrying a code and a quantity. A REQUIRED miss is a hard fail
 * and a non-zero exit — it means an assumed field name is wrong and the mapper
 * (only that file) needs the real key.
 */
class ValidateAggregatorPayload extends Command
{
    protected $signature = 'aggregator:validate-payload
        {provider : zomato | swiggy}
        {file     : Path to a captured JSON payload}
        {--json   : Emit the result as JSON instead of a table}';

    protected $description = 'Run a captured aggregator webhook payload through the real mapper and report which assumed fields resolved.';

    public function handle(): int
    {
        $provider = strtolower((string) $this->argument('provider'));
        if (! in_array($provider, ['zomato', 'swiggy'], true)) {
            $this->error("provider must be 'zomato' or 'swiggy'.");

            return self::INVALID;
        }

        $file = (string) $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($file), true);
        if (! is_array($payload)) {
            $this->error('The file is not a JSON object.');

            return self::FAILURE;
        }

        $mapper = $provider === 'zomato' ? ZomatoPayloadMapper::class : SwiggyPayloadMapper::class;
        $rows = $this->evaluate($mapper, $payload);

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->render($provider, $file, $rows);
        }

        $requiredMiss = array_filter($rows, fn ($r) => $r['required'] && ! $r['ok']);

        return $requiredMiss ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  class-string  $m  the mapper
     * @return list<array{field:string, required:bool, ok:bool, value:string}>
     */
    private function evaluate(string $m, array $payload): array
    {
        $lines = $m::lines($payload);
        $first = $lines[0] ?? null;

        $rows = [
            $this->row('outlet id (external_store_id)', true, $m::storeId($payload)),
            $this->row('order id (idempotency key)', true, $m::orderId($payload)),
            $this->row('event', false, $m::event($payload)),
            $this->row('is cancellation', false, $m::isCancellation($payload) ? 'yes' : 'no'),
            $this->row('line count', true, count($lines) > 0 ? (string) count($lines) : null),
        ];

        // Per-line extraction, sampled on the first line — a code and a quantity
        // are what mapOrder needs to resolve a line to a local menu item.
        if (is_array($first)) {
            $rows[] = $this->row('· line[0] code', true, $m::lineCode($first));
            $qty = $m::lineQty($first);
            $rows[] = $this->row('· line[0] quantity', true, $qty > 0 ? (string) $qty : null);
            $rows[] = $this->row('· line[0] name', false, $m::lineName($first));
        }

        $customer = $m::customer($payload);
        $rows[] = $this->row('customer name', false, $customer['name'] ?? null);
        $rows[] = $this->row('customer phone', false, $customer['phone'] ?? $customer['mobile'] ?? null);
        $rows[] = $this->row('address', false, $m::address($payload));
        $rows[] = $this->row('delivery mode', false, $m::deliveryMode($payload));
        $rows[] = $this->row('channel fee', false, (string) $m::channelFee($payload));
        $rows[] = $this->row('instructions', false, $m::instructions($payload));

        return $rows;
    }

    /** @return array{field:string, required:bool, ok:bool, value:string} */
    private function row(string $field, bool $required, ?string $value): array
    {
        $ok = $value !== null && $value !== '';

        return [
            'field' => $field,
            'required' => $required,
            'ok' => $ok,
            'value' => $ok ? $this->clip($value) : '—',
        ];
    }

    private function clip(string $v): string
    {
        return mb_strlen($v) > 48 ? mb_substr($v, 0, 47).'…' : $v;
    }

    private function render(string $provider, string $file, array $rows): void
    {
        $this->line('');
        $this->info(ucfirst($provider).' payload · '.$file);
        $this->line('');

        $table = array_map(fn ($r) => [
            $r['ok'] ? '<fg=green>✓</>' : ($r['required'] ? '<fg=red>✗</>' : '<fg=yellow>·</>'),
            $r['field'],
            $r['required'] ? 'required' : 'optional',
            $r['value'],
        ], $rows);
        $this->table(['', 'field', '', 'resolved value'], $table);

        $missReq = array_values(array_filter($rows, fn ($r) => $r['required'] && ! $r['ok']));
        $missOpt = array_values(array_filter($rows, fn ($r) => ! $r['required'] && ! $r['ok']));

        $this->line('');
        if ($missReq) {
            $this->error('  '.count($missReq).' REQUIRED field(s) did not resolve — the ingest pipeline would reject this webhook:');
            foreach ($missReq as $r) {
                $this->line('    <fg=red>'.$r['field'].'</>');
            }
            $mapperFile = $provider === 'zomato'
                ? 'app/Modules/Channels/Services/Aggregators/Zomato/ZomatoPayloadMapper.php'
                : 'app/Modules/Channels/Services/Aggregators/Swiggy/SwiggyPayloadMapper.php';
            $this->line('');
            $this->comment('  Fix: open '.$mapperFile.' and add the real key to that accessor\'s fallback list.');
            $this->comment('  Only that one file changes — the provider, ingest pipeline and tests stay put.');
        } else {
            $this->info('  All required fields resolved. This payload would ingest.');
        }
        if ($missOpt) {
            $this->line('');
            $this->comment('  '.count($missOpt).' optional field(s) unresolved (not blocking): '
                .implode(', ', array_map(fn ($r) => $r['field'], $missOpt)));
        }
        $this->line('');
    }
}
