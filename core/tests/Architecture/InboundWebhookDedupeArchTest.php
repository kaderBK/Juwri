<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P6-100: every inbound provider callback (webhook/*, ipn/*, provider hooks) is covered by a replay/duplicate
 * guard — providers retry, re-deliver and reorder, so a second delivery must never have a second effect. Each route
 * below names its mechanism and the file that holds it; the test also checks that file still contains the guard
 * (a marker), so removing a guard or adding an unlisted webhook route fails here.
 */
class InboundWebhookDedupeArchTest extends TestCase
{
    /**
     * "METHOD uri" => [file holding the guard (relative to core/), marker that must appear in it, why it is safe].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const COVERED = [
        'POST ipn/{gateway}' => ['app/Modules/Payments/Services/Gateways/Settlement.php', 'function claimPaid',
            'returns settle only a still-pending transaction (Settlement::pending / claimPaid); the credit is one outbox message per transaction'],
        'POST webhook/{gateway}' => ['app/Modules/Payments/Services/Webhooks/WebhookReceipts.php', 'insertOrIgnore',
            'gateway adapters claim webhook_receipts per provider event (WebhookReceipts::once) and settle with claimPaid'],
        'POST webhook/{gateway}/store/{store}' => ['app/Modules/Payments/Services/Webhooks/WebhookReceipts.php', 'insertOrIgnore',
            'store webhooks: WebhookReceipts::once per (store, event) + claimPaid'],
        'POST webhook/in-app/apple' => ['app/Modules/Payments/Services/InApp/InAppBilling.php', 'WebhookReceipts::once',
            'notificationUUID receipt'],
        'POST webhook/in-app/google' => ['app/Modules/Payments/Services/InApp/InAppBilling.php', 'WebhookReceipts::once',
            'Pub/Sub messageId receipt'],
        'POST webhook/softpos/{provider}/store/{store}' => ['app/Modules/Payments/Services/Terminal/SoftPos/SoftPos.php', 'WebhookReceipts::once',
            'receipt per (store, signed body); reconcile re-reads provider state'],
        'POST webhook/paylink-partner' => ['app/Modules/Payments/Http/Controllers/User/PaylinkPartnerController.php', 'webhook',
            'activation is idempotent: an ACTIVE merchant returns early and keys are re-pulled from Paylink, never from the body'],
        'POST studio/stripe-webhook' => ['app/Modules/Content/Http/Controllers/Studio/StudioController.php', 'WebhookDeduplication',
            'receipt per checkout session'],
        'POST webhook/fiscal/eta/{token}' => ['app/Modules/Fiscal/Http/Controllers/User/FiscalController.php', 'canTransitionTo',
            'state machine: a replayed callback cannot leave a terminal state'],
        'POST webhook/mail/{provider}' => ['app/Modules/Messaging/Models/EmailSuppression.php', 'UniqueConstraintViolationException',
            'one suppression row per address (unique email, upsert); a replayed bounce/complaint only bumps the events counter'],
        'POST webhook/sms/inbound' => ['app/Modules/Messaging/Http/Controllers/SmsWebhookController.php', 'WebhookDeduplication::class',
            'receipt per (store, MessageSid); STOP/START are idempotent'],
        'POST webhook/whatsapp/{provider}' => ['app/Modules/Messaging/Services/WhatsApp/WhatsAppWebhookProcessor.php', 'WhatsAppEvent::claim',
            'unique claim per inbound message id / status; status callbacks forward-only'],
        'POST webhook/courier/{provider}' => ['app/Modules/Delivery/Http/Controllers/CourierWebhookController.php', 'WebhookDeduplication::class',
            'receipt per signed body; status forward-only (rank), terminal final, location only when newer'],
        'POST webhook/delivery/{provider}' => ['app/Modules/Channels/Services/Aggregators/AggregatorIngestor.php', 'UniqueConstraintViolationException',
            'unique (provider, external_id) claim row per aggregator order'],
        'POST webhook/zomato/orders' => ['app/Modules/Channels/Services/Aggregators/AggregatorIngestor.php', 'UniqueConstraintViolationException',
            'create: unique external id; cancel: status guard + locked stock restore'],
        'POST webhook/swiggy/orders' => ['app/Modules/Channels/Services/Aggregators/AggregatorIngestor.php', 'UniqueConstraintViolationException',
            'create: unique external id; cancel: status guard + locked stock restore'],
        'POST webhook/hungerstation' => ['app/Modules/Channels/Services/HungerStation/HungerStationOrderService.php', 'delivery_orders',
            'unique external id per order in a DB transaction'],
        'POST webhook/deliverect/register' => ['app/Modules/Channels/Http/Controllers/DeliverectPosController.php', 'channel_link_id',
            'idempotent overwrite of the channel link'],
        'POST webhook/deliverect/syncproducts' => ['app/Modules/Channels/Http/Controllers/DeliverectPosController.php', 'syncProducts',
            'read-only menu response'],
        'POST webhook/deliverect/ordercancel' => ['app/Modules/Channels/Http/Controllers/DeliverectPosController.php', "!== 'cancelled'",
            'status guard + locked stock restore (stock_restored_at)'],
        'POST webhook/foodics/{token}' => ['app/Modules/Channels/Http/Controllers/FoodicsWebhookController.php', 'RANK',
            'status mirror forward-only; final statuses stay final'],
        'POST webhook/marn/{token}' => ['app/Modules/Channels/Http/Controllers/MarnWebhookController.php', '$currentRank >= $nextRank',
            'orderState mirror forward-only'],
        'POST webhook/square' => ['app/Modules/Channels/Http/Controllers/SquareWebhookController.php', 'square_version',
            'only a newer order version; COMPLETED/CANCELED final'],
        'POST voice/{provider}/incoming' => ['app/Modules/Agents/Http/Controllers/VoiceWebhookController.php', 'BridgeToken::issue',
            'answers TwiML only; the media bridge token is single-use per call id'],
        'POST voice/{provider}/status' => ['app/Modules/Agents/Services/Telephony/VoiceSession.php', 'idempotent per call SID',
            'finalize keeps the first outcome; the cost row is idempotent per call SID'],
    ];

    public function test_every_inbound_provider_callback_is_covered_by_a_dedupe_guard(): void
    {
        $found = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $uri = $route->uri();
            if (! $this->isInboundCallback($uri)) {
                continue;
            }
            $methods = $route->methods();
            // Route::any registers every verb; such a route is listed once, under POST.
            foreach (in_array('POST', $methods, true) ? ['POST'] : array_diff($methods, ['GET', 'HEAD', 'OPTIONS']) as $method) {
                $found[$method.' '.$uri] = true;
            }
        }

        $missing = array_values(array_diff(array_keys($found), array_keys(self::COVERED)));
        $this->assertSame([], $missing, "inbound provider callbacks without a listed dedupe guard (add one to COVERED with its mechanism):\n".implode("\n", $missing));

        $stale = array_values(array_diff(array_keys(self::COVERED), array_keys($found)));
        $this->assertSame([], $stale, "COVERED lists routes that no longer exist:\n".implode("\n", $stale));
    }

    public function test_each_listed_guard_is_still_in_its_file(): void
    {
        $broken = [];
        foreach (self::COVERED as $route => [$file, $marker, $why]) {
            $path = base_path($file);
            if (! is_file($path) || ! str_contains((string) file_get_contents($path), $marker)) {
                $broken[] = "$route: '$marker' not found in $file ($why)";
            }
        }
        $this->assertSame([], $broken, implode("\n", $broken));
    }

    private function isInboundCallback(string $uri): bool
    {
        if (str_contains($uri, 'api/webhooks') || str_contains($uri, 'webhook-endpoints') || str_starts_with($uri, 'admin')) {
            return false; // owner/admin CRUD of outbound webhooks, not provider callbacks
        }

        return (bool) preg_match('#(^|/)(webhook|ipn)(/|$)|[-_]webhook($|/)|^voice/\{provider\}/#', $uri);
    }
}
