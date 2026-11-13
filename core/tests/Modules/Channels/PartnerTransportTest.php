<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Channels\Contracts\PartnerTransport;
use Juwri\Modules\Channels\Jobs\ProcessHungerStationOrderJob;
use Juwri\Modules\Channels\Services\Aggregators\Jahez\FakeJahezClient;
use Juwri\Modules\Channels\Services\Aggregators\Jahez\JahezClientFactory;
use Juwri\Modules\Channels\Services\Aggregators\Jahez\JahezClientInterface;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\FakeKeetaClient;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaClientFactory;
use Juwri\Modules\Channels\Services\Aggregators\UberEats\FakeUberEatsClient;
use Juwri\Modules\Channels\Services\Aggregators\UberEats\UberEatsClientFactory;
use Juwri\Modules\Channels\Services\HungerStation\FakeHungerStationClient;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationCatalogService;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationClient;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationClientFactory;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationClientInterface;
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FakeFoodicsClient;
use Juwri\Modules\Channels\Services\PosBridge\Foodics\FoodicsClientFactory;
use Juwri\Modules\Channels\Services\PosBridge\Marn\FakeMarnClient;
use Juwri\Modules\Channels\Services\PosBridge\Marn\MarnClientFactory;
use Juwri\Modules\Channels\Services\PosBridge\Square\SquareClientFactory;
use Juwri\Modules\Channels\Services\Transport\DirectPartnerTransport;
use Juwri\Modules\Channels\Services\Transport\PartnerTransports;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * P1-13 13c-R (owner decision A): the Channels "relay transport" extension point. Community binds the direct
 * transport — every partner-credential connector (HungerStation, Jahez, Keeta, Uber Eats, Deliverect, Foodics, Marn)
 * builds the same client and sends the same requests as before, and checks the partner's own webhook signature. A
 * later juwri Cloud partner relay binds another PartnerTransport; a fake one here receives the calls instead.
 * Merchant-own-credential connectors (Square, Clover, custom) never go through it.
 */
class PartnerTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_community_binding_is_the_direct_transport_and_clients_are_unchanged(): void
    {
        $this->assertInstanceOf(DirectPartnerTransport::class, app(PartnerTransport::class));
        $this->seedStores(1);
        $storeId = 1;

        // No partner credentials → each connector's Fake, exactly as before.
        $this->assertInstanceOf(FakeHungerStationClient::class, HungerStationClientFactory::make($storeId));
        $this->assertInstanceOf(FakeJahezClient::class, JahezClientFactory::make($storeId));
        $this->assertInstanceOf(FakeKeetaClient::class, KeetaClientFactory::make($storeId));
        $this->assertInstanceOf(FakeUberEatsClient::class, UberEatsClientFactory::make($storeId));
        $this->assertInstanceOf(FakeFoodicsClient::class, FoodicsClientFactory::make($storeId));
        $this->assertInstanceOf(FakeMarnClient::class, MarnClientFactory::make($storeId));

        // Real driver + stored credentials → the real HungerStation client.
        $this->hungerStationCredentials($storeId);
        $this->assertInstanceOf(HungerStationClient::class, HungerStationClientFactory::make($storeId));

        $this->assertSame(['hungerstation', 'jahez', 'keeta', 'uber_eats', 'deliverect', 'foodics', 'marn'], PartnerTransport::PARTNERS);
        foreach (['square', 'clover', 'custom'] as $merchantOwn) {
            $this->assertFalse(PartnerTransports::routes($merchantOwn), "$merchantOwn uses the merchant's own credentials");
        }
    }

    public function test_direct_hungerstation_requests_are_byte_identical(): void
    {
        $this->seedStores(1);
        $storeId = 1;
        $this->hungerStationCredentials($storeId);
        $branch = $this->hungerStationBranch($storeId);
        $this->mappedItem($storeId);
        Http::fake([
            'hs.test/oauth/token' => Http::response(['access_token' => 'tok_direct']),
            'hs.test/v2/*' => Http::response(['job_id' => 'J1', 'status' => 'QUEUED']),
        ]);

        app(HungerStationCatalogService::class)->pushBranch($storeId, $branch->id);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        $this->assertCount(2, $sent);
        $this->assertSame('POST', $sent[0]->method());
        $this->assertSame('https://hs.test/oauth/token', $sent[0]->url());
        $this->assertSame('grant_type=client_credentials&client_id=cid&client_secret=sec', $sent[0]->body());
        $this->assertSame(['application/x-www-form-urlencoded'], $sent[0]->header('Content-Type'));
        $this->assertSame('PUT', $sent[1]->method());
        $this->assertSame('https://hs.test/v2/chains/CH1/vendors/V1/catalog', $sent[1]->url());
        $this->assertSame(['Bearer tok_direct'], $sent[1]->header('Authorization'));
        $this->assertSame(['application/json'], $sent[1]->header('Accept'));
        $this->assertSame(json_encode(['items' => [['sku' => 'SKU-1', 'price' => 12.5, 'active' => true, 'quantity' => null]]]), $sent[1]->body());
    }

    public function test_a_relay_binding_receives_the_outbound_calls(): void
    {
        $relay = $this->bindRelay();
        $this->seedStores(1);
        $storeId = 1;
        $this->hungerStationCredentials($storeId);
        $branch = $this->hungerStationBranch($storeId);
        $this->mappedItem($storeId);
        Http::fake();

        app(HungerStationCatalogService::class)->pushBranch($storeId, $branch->id);
        $jahez = JahezClientFactory::make($storeId);

        $this->assertSame([['hungerstation', $storeId, HungerStationClientInterface::class], ['jahez', $storeId, JahezClientInterface::class]], $relay->clients);
        $this->assertSame([['updateCatalog', 'CH1', 'V1', [['sku' => 'SKU-1', 'price' => 12.5, 'active' => true, 'quantity' => null]]]], $relay->hungerStation->calls);
        $this->assertInstanceOf(JahezClientInterface::class, $jahez);
        Http::assertNothingSent(); // nothing went to the partner directly

        // Merchant-own credentials never go through the relay.
        SquareClientFactory::make($storeId);
        $this->assertCount(2, $relay->clients);
    }

    public function test_a_relay_binding_verifies_the_webhooks_it_forwards(): void
    {
        Queue::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->seedStores(1);
        $storeId = 1;
        $this->hungerStationBranch($storeId);
        $body = ['vendor_id' => 'V1', 'order_id' => 'O-1'];

        // Direct (Community default): no HungerStation signature → refused.
        $this->postJson('/webhook/hungerstation', $body, ['X-Relay-Signature' => 'ok'])->assertStatus(401);

        $relay = $this->bindRelay();
        $this->postJson('/webhook/hungerstation', $body, ['X-Relay-Signature' => 'ok'])->assertStatus(202);
        $this->postJson('/webhook/hungerstation', $body, ['X-Relay-Signature' => 'forged'])->assertStatus(401);
        $this->assertSame(['hungerstation', 'hungerstation'], $relay->inbound);
        Queue::assertPushed(ProcessHungerStationOrderJob::class, 1);
    }

    private function bindRelay(): RecordingRelayTransport
    {
        $relay = new RecordingRelayTransport;
        $this->app->instance(PartnerTransport::class, $relay);

        return $relay;
    }

    private function hungerStationCredentials(int $storeId): void
    {
        config()->set('services.hungerstation.driver', 'real');
        Credential::create([
            'store_id' => $storeId, 'feature' => 'hungerstation', 'provider' => 'hungerstation', 'active' => true,
            'secrets' => ['client_id' => 'cid', 'client_secret' => 'sec', 'base_url' => 'https://hs.test', 'auth_url' => 'https://hs.test/oauth/token'],
        ]);
    }

    private function hungerStationBranch(int $storeId): Branch
    {
        return Branch::create(['store_id' => $storeId, 'name' => 'Main', 'hs_vendor_id' => 'V1', 'hs_chain_id' => 'CH1']);
    }

    private function mappedItem(int $storeId): Menu
    {
        $category = MenuCategory::create(['store_id' => $storeId, 'name' => 'Main', 'active' => '1']);

        return Menu::create(['store_id' => $storeId, 'category_id' => $category->id, 'name' => 'Shawarma', 'price' => 12.5, 'active' => '1', 'hs_sku' => 'SKU-1']);
    }
}

/** A stand-in for the later juwri Cloud partner relay: records what reaches it. */
final class RecordingRelayTransport implements PartnerTransport
{
    /** @var list<array{0: string, 1: ?int, 2: string}> */
    public array $clients = [];

    /** @var list<string> */
    public array $inbound = [];

    public RecordingHungerStationClient $hungerStation;

    public function __construct()
    {
        $this->hungerStation = new RecordingHungerStationClient;
    }

    public function client(string $partner, ?int $storeId, string $interface, Closure $direct): object
    {
        $this->clients[] = [$partner, $storeId, $interface];

        return $interface === HungerStationClientInterface::class ? $this->hungerStation : new FakeJahezClient;
    }

    public function verifyInbound(string $partner, Request $request, Closure $direct): bool
    {
        $this->inbound[] = $partner;

        return $request->header('X-Relay-Signature') === 'ok';
    }
}

final class RecordingHungerStationClient implements HungerStationClientInterface
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function fetchOrder(string $chainId, string $orderId): array
    {
        $this->calls[] = ['fetchOrder', $chainId, $orderId];

        return [];
    }

    public function updateOrderStatus(string $chainId, string $orderId, string $status, array $extra = []): array
    {
        $this->calls[] = ['updateOrderStatus', $chainId, $orderId, $status];

        return [];
    }

    public function listCatalog(string $chainId, string $vendorId): array
    {
        $this->calls[] = ['listCatalog', $chainId, $vendorId];

        return [];
    }

    public function updateCatalog(string $chainId, string $vendorId, array $items): array
    {
        $this->calls[] = ['updateCatalog', $chainId, $vendorId, $items];

        return ['job_id' => 'R1', 'status' => 'QUEUED'];
    }

    public function exportCatalog(string $chainId, string $vendorId): array
    {
        $this->calls[] = ['exportCatalog', $chainId, $vendorId];

        return [];
    }
}
