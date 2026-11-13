<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Models\Order;
use App\Models\OrderItem;
use App\Support\HostingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Accounting\Jobs\SyncInvoiceJob;
use Juwri\Modules\Accounting\Models\ExternalInvoice;
use Juwri\Modules\Channels\Jobs\ProcessHungerStationOrderJob;
use Juwri\Modules\Channels\Jobs\PushPosOrderJob;
use Juwri\Modules\Fiscal\Jobs\SubmitFiscalDocumentJob;
use Juwri\Modules\Fiscal\Models\FiscalCertificate;
use Juwri\Modules\Fiscal\Models\FiscalDevice;
use Juwri\Modules\Fiscal\Models\FiscalDocument;
use Juwri\Modules\Fiscal\Services\Eta\EtaHttpClient;
use Juwri\Modules\Fiscal\Services\FiscalChain;
use Juwri\Modules\Fiscal\Services\FiscalDriverManager;
use Juwri\Modules\Fiscal\Services\Jofotara\JofotaraHttpClient;
use Juwri\Modules\Fiscal\Services\Zatca\Contracts\ZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\FakeZatcaClient;
use Juwri\Modules\Fiscal\Services\Zatca\ZatcaHttpClient;
use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Contracts\Outbox;
use Juwri\Modules\Payments\Jobs\RelayOutboxJob;
use Juwri\Modules\Payments\Services\Webhooks\TransactionalOutbox;
use Juwri\Modules\Payments\Services\Webhooks\WebhookReceipts;
use Tests\TestCase;

/**
 * P6-139: a queue job killed at any point (a shared host reaping the cron drain, OOM, deploy) and then re-run never
 * doubles money, fiscal or journal effects. Two kill points per job: after the outside call but before our commit,
 * and after our commit but before the ack. Inventory: the killed-jobs list.
 */
class KilledJobMoneySafetyTest extends TestCase
{
    use RefreshDatabase;

    /* --------------------------------------------------------------- outbox (credit, fiscal issue, GL, side effects) */

    public function test_outbox_kill_after_commit_before_ack_never_runs_the_handler_twice(): void
    {
        $outbox = app(Outbox::class);
        $runs = 0;
        $atCommit = [];
        $outbox->handle('p6139.effect', function (array $p) use (&$runs, &$atCommit, $outbox) {
            $runs++;
            DB::table('outbox_messages')->where('type', 'p6139.marker')->delete(); // a DB write of the handler
            // The instant the handler's transaction commits (the earliest a kill can land after it): the message must
            // already read "done". Before P6-139 it read "processing" here, and a kill now meant a second run.
            DB::afterCommit(function () use (&$atCommit, $outbox, $p) {
                $atCommit[] = $outbox->status((int) $p['id']);
            });
        });
        $id = (int) $outbox->record('p6139.effect', ['n' => 1], 'p6139:1');
        DB::table('outbox_messages')->where('id', $id)->update(['payload' => json_encode(['id' => $id])]);

        // Kill after commit, before the queue ack: the job row is never deleted, so the queue hands it out again.
        new RelayOutboxJob([$id])->handle($outbox);
        $this->assertSame(['done'], $atCommit);
        new RelayOutboxJob([$id])->handle($outbox); // the re-delivered job
        DB::table('outbox_messages')->where('id', $id)->update(['claimed_at' => now()->subMinutes(TransactionalOutbox::STALE_AFTER_MINUTES + 1)]);
        $outbox->relay(); // and the scheduled relay's stale sweep
        $this->assertSame(1, $runs);
        $this->assertSame('done', $outbox->status($id));
    }

    public function test_outbox_kill_mid_handler_rolls_back_and_the_rerun_performs_it_once(): void
    {
        $outbox = app(Outbox::class);
        $kill = true;
        $outbox->handle('p6139.ledger', function (array $p) use (&$kill) {
            DB::table('outbox_messages')->insert(['type' => 'p6139.journal', 'payload' => '{}', 'dedupe_key' => 'journal:'.$p['n'],
                'status' => 'done', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            if ($kill) {
                $kill = false;
                throw new \RuntimeException('killed after the outside call, before commit');
            }
        });
        $id = (int) $outbox->record('p6139.ledger', ['n' => 7], 'p6139:7');

        $outbox->relay([$id]);
        $this->assertSame(0, DB::table('outbox_messages')->where('dedupe_key', 'journal:7')->count(), 'the killed run left nothing');

        DB::table('outbox_messages')->where('id', $id)->update(['available_at' => now()->subMinute()]);
        $outbox->relay([$id]);
        $outbox->relay([$id]);
        $this->assertSame(1, DB::table('outbox_messages')->where('dedupe_key', 'journal:7')->count());
        $this->assertSame('done', $outbox->status($id));
    }

    /* ------------------------------------------------------------------------------------------- e-invoice (ZATCA) */

    private function zatcaDoc(int $storeId): FiscalDocument
    {
        FiscalDevice::create(['store_id' => $storeId, 'device_key' => 'EGS-K', 'country' => 'SA', 'status' => 'production']);
        FiscalCertificate::create(['store_id' => $storeId, 'device_key' => 'EGS-K', 'kind' => 'pcsid', 'pem' => 'CERT', 'active' => true, 'meta' => ['secret' => 's']]);

        return FiscalChain::issue($storeId, 'EGS-K', [
            'country' => 'SA', 'type' => 'simplified', 'total' => 115, 'tax_total' => 15, 'currency' => 'SAR',
            'payload' => '<Invoice><cbc:ID>SIM-K</cbc:ID></Invoice>',
        ]);
    }

    public function test_fiscal_submit_killed_after_the_authority_answered_resends_the_same_document_never_a_second(): void
    {
        $this->seedStores(931);
        $client = new class extends FakeZatcaClient
        {
            /** @var list<string> */
            public array $uuids = [];

            public bool $killNext = true;

            public function reportInvoice(string $certificate, string $secret, array $document): array
            {
                $this->uuids[] = (string) $document['uuid'];
                $res = parent::reportInvoice($certificate, $secret, $document);
                if ($this->killNext) {
                    $this->killNext = false;
                    throw new \RuntimeException('killed after ZATCA answered, before our status write');
                }

                return $res;
            }
        };
        $this->app->instance(ZatcaClient::class, $client);
        $doc = $this->zatcaDoc(931);
        $chainBefore = FiscalDocument::where('store_id', 931)->count();

        try {
            new SubmitFiscalDocumentJob($doc->id)->handle(app(FiscalDriverManager::class));
        } catch (\RuntimeException) {
        }
        $this->assertSame('submitted', $doc->fresh()->status, 'the killed run leaves an auditable "submitted", never a lost document');

        new SubmitFiscalDocumentJob($doc->id)->handle(app(FiscalDriverManager::class)); // the queue's retry
        new SubmitFiscalDocumentJob($doc->id)->handle(app(FiscalDriverManager::class)); // a stray duplicate job

        $this->assertSame('accepted', $doc->fresh()->status);
        $this->assertSame([$doc->uuid, $doc->uuid], $client->uuids, 'the re-send is the same invoice (same UUID); accepted is never re-sent');
        $this->assertSame($chainBefore, FiscalDocument::where('store_id', 931)->count(), 'no second document on the chain');
    }

    public function test_a_rejected_document_is_never_resent_by_a_rerun_job(): void
    {
        $this->seedStores(932);
        $client = new FakeZatcaClient;
        $this->app->instance(ZatcaClient::class, $client);
        $doc = $this->zatcaDoc(932);
        $client->rejectNext = true;
        new SubmitFiscalDocumentJob($doc->id)->handle(app(FiscalDriverManager::class));
        $this->assertSame('rejected', $doc->fresh()->status);

        $client->calls = [];
        new SubmitFiscalDocumentJob($doc->id)->handle(app(FiscalDriverManager::class));
        $this->assertSame([], $client->calls);
    }

    public function test_zatca_409_already_reported_on_a_resend_is_recorded_as_accepted(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'Invoice was already reported']]], 409)]);
        $res = new ZatcaHttpClient('https://gw.test/api')->reportInvoice('CERT', 's', ['xml' => '<x/>', 'uuid' => 'u-1', 'hash' => 'h']);

        $this->assertSame('accepted', $res['status']);
        $this->assertTrue($res['response']['already_processed']);
    }

    public function test_zatca_409_on_a_clearance_resend_flags_the_missing_cleared_xml(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['message' => 'Invoice was already cleared']]], 409)]);
        $res = new ZatcaHttpClient('https://gw.test/api')->clearInvoice('CERT', 's', ['xml' => '<x/>', 'uuid' => 'u-1', 'hash' => 'h']);

        $this->assertSame('accepted', $res['status']);
        $this->assertNull($res['cleared_xml']);
        $this->assertTrue($res['response']['cleared_xml_missing']);
    }

    public function test_queue_retry_after_has_a_floor_above_the_longest_money_job(): void
    {
        $_ENV['QUEUE_RETRY_AFTER'] = $_SERVER['QUEUE_RETRY_AFTER'] = '60';
        try {
            $queue = require base_path('config/queue.php');
        } finally {
            unset($_ENV['QUEUE_RETRY_AFTER'], $_SERVER['QUEUE_RETRY_AFTER']);
        }
        $this->assertSame(150, $queue['connections']['database']['retry_after']);
        $this->assertSame(150, $queue['connections']['redis']['retry_after']);
    }

    /* --------------------------------------------------------------------------------------- e-invoice (ETA, JO) */

    public function test_eta_server_error_or_duplicate_submission_is_retried_never_counted_accepted(): void
    {
        $eta = new EtaHttpClient('https://id.eta.test', 'https://api.eta.test', 'https://lookup.eta.test');
        $codes = [500, 503, 422, 429, 401];
        $seq = Http::sequence();
        foreach ($codes as $code) {
            $seq->push(['error' => 'x'], $code);
        }
        $seq->push(['submissionId' => 'S1', 'acceptedDocuments' => [['uuid' => 'r-1']], 'rejectedDocuments' => []], 202);
        Http::fake(['*' => $seq]);
        foreach ($codes as $code) {
            try {
                $eta->submitReceipts('tok', [['header' => ['uuid' => 'r-1']]]);
                $this->fail("HTTP {$code} must throw (retry), not count as a verdict");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString((string) $code, $e->getMessage());
            }
        }
        $this->assertSame('accepted', $eta->submitReceipts('tok', [['header' => ['uuid' => 'r-1']]])['status']);
    }

    public function test_jofotara_server_error_is_retried_never_a_rejection_that_invites_a_second_invoice(): void
    {
        $jo = new JofotaraHttpClient('https://backend.jofotara.test');
        Http::fake(['*' => Http::response('Bad gateway', 502)]);
        $this->expectException(\RuntimeException::class);
        $jo->submit(['clientId' => 'c', 'secret' => 's'], ['invoiceNumber' => 'INV-1', 'uuid' => 'u']);
    }

    /* ----------------------------------------------------------------------- external journal (QuickBooks / Xero) */

    public function test_accounting_sync_killed_after_the_provider_booked_it_resends_with_the_same_idempotency_key(): void
    {
        SsrfGuard::$resolver = static fn (string $h): array => ['93.184.216.34'];
        Http::fake(['xero.example/*' => Http::response(['Invoices' => [['InvoiceID' => 'XR-K']]], 200)]);
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $user->id, 'slug' => 'k'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        Credential::create(['store_id' => $store->id, 'feature' => 'accounting', 'provider' => 'xero', 'active' => true,
            'secrets' => ['provider' => 'xero', 'access_token' => 'tok', 'tenant_id' => 'T', 'base_url' => 'https://xero.example/api.xro/2.0']]);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Tea', 'price' => 3, 'active' => 1]);
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 1]);

        $kill = true;
        ExternalInvoice::creating(function () use (&$kill) {
            if ($kill) {
                $kill = false;
                throw new \RuntimeException('killed after the provider booked the sale, before our row');
            }
        });
        try {
            new SyncInvoiceJob($order->id)->handle();
        } catch (\RuntimeException) {
        }
        new SyncInvoiceJob($order->id)->handle(); // the retry
        new SyncInvoiceJob($order->id)->handle(); // a stray duplicate

        $keys = [];
        Http::assertSent(function ($request) use (&$keys) {
            $keys[] = $request->header('Idempotency-Key')[0] ?? null;

            return true;
        });
        $this->assertCount(2, $keys, 'one re-send after the kill, none after the row exists');
        $this->assertSame(['juwri-order-'.$order->id.'-xero', 'juwri-order-'.$order->id.'-xero'], $keys,
            'same key both times: the provider answers the first invoice, no second sale is booked');
        $this->assertSame(1, ExternalInvoice::where('order_id', $order->id)->count());
    }

    /* -------------------------------------------------------------------------------------- webhook state changes */

    public function test_a_webhook_killed_mid_processing_is_not_acknowledged_until_a_retry_processes_it(): void
    {
        $this->app->instance('request', Request::create('/webhook/stripe', 'POST'));
        $runs = 0;
        // Delivery A claims the event and is killed (no release: the process is gone).
        $this->assertNotNull(WebhookReceipts::claim('stripe', 'evt_killed'));

        // The gateway's quick retry is told to come back (never a 2xx that would end its retries).
        $answer = WebhookReceipts::once('stripe', 'evt_killed', function () use (&$runs) {
            $runs++;

            return response('ok', 200);
        });
        $this->assertSame(503, $answer->getStatusCode());

        // A later retry takes the dead claim over and processes it exactly once; copies after that are 200 no-ops.
        DB::table('webhook_receipts')->update(['claimed_at' => now()->subMinutes(WebhookReceipts::STALE_AFTER_MINUTES + 1)]);
        foreach ([1, 2] as $_) {
            $answer = WebhookReceipts::once('stripe', 'evt_killed', function () use (&$runs) {
                $runs++;

                return response('ok', 200);
            });
            $this->assertSame(200, $answer->getStatusCode());
        }
        $this->assertSame(1, $runs);
    }

    /* ---------------------------------------------------------------------------- timing: retry_after > runtime */

    public function test_every_money_job_finishes_inside_retry_after_on_every_hosting_profile(): void
    {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        foreach ([RelayOutboxJob::class, SubmitFiscalDocumentJob::class, SyncInvoiceJob::class, DeliverWebhookJob::class,
            PushPosOrderJob::class, ProcessHungerStationOrderJob::class] as $job) {
            $timeout = (int) (new \ReflectionClass($job))->getProperty('timeout')->getDefaultValue();
            $this->assertLessThan($retryAfter, $timeout, "{$job}: \$timeout must stay under retry_after or a slow run is handed out twice");
        }
        // The outbox / webhook stale takeovers wait far longer than any relay run can take.
        $this->assertGreaterThan(120, TransactionalOutbox::STALE_AFTER_MINUTES * 60);
        $this->assertGreaterThan(120, WebhookReceipts::STALE_AFTER_MINUTES * 60);

        foreach (HostingProfile::PROFILES as $profile) {
            $default = (int) HostingProfile::default('default_max_time', $profile);
            $heavy = (int) HostingProfile::default('heavy_max_time', $profile);
            $this->assertLessThan($retryAfter, $default, "{$profile}: default drain cap under retry_after");
            $this->assertLessThan((int) config('queue.connections.heavy.retry_after'), $heavy, "{$profile}: heavy drain cap under heavy retry_after");
        }
        $this->assertSame([45, 600], [HostingProfile::default('default_max_time', 'shared'), HostingProfile::default('heavy_max_time', 'shared')]);
        $this->assertSame([50, 1800], [HostingProfile::default('default_max_time', 'cpanel-vps'), HostingProfile::default('heavy_max_time', 'cpanel-vps')]);
    }
}
