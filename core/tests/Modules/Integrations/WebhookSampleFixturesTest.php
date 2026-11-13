<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Models\WebhookEndpoint;
use Tests\TestCase;

/**
 * F0-5 (integrations pack): canonical webhook sample fixtures.
 *
 * The Zapier / Make / n8n packages ship one static "sample" per trigger, and
 * Zapier review checks D010/D012/T004/T006 require every sample key to be a real
 * delivered key with an `id` and ISO-8601 dates. These fixtures are that single
 * source of truth (the packages copy them verbatim at build). This test freezes:
 *  - a fixture exists for every subscribable event (and no orphan fixtures),
 *  - each is a valid delivery envelope (top-level id/event/created_at/data),
 *  - every `data` key is one a live dispatcher actually sends (subset rule),
 *  - every date-like value is ISO-8601 with a timezone offset.
 */
class WebhookSampleFixturesTest extends TestCase
{
    private const DIR = 'resources/fixtures/webhook-samples';

    /**
     * The union of `data` keys each event's LIVE dispatch call sites send, mined
     * from the code (kept in sync by hand, like FeatureGateAuditTest's $covered):
     *  order.created   OrderApiService:660 / StoreController:3414 / PosService:762 / HungerStation:135
     *  order.paid      PaymentController:294 / SplitBillService:142 (via)
     *  order.status_changed StoreController:151 / StaffAppController:85 (by_staff)
     *  refund.completed ManagesOrders:456
     *  reservation.*   ReservationController / BookingServerController (source: google)
     *  customer.created Customer:71 · shift.* PosService:148/228
     *  menu.updated    FiresMenuWebhook:67 · entitlement.changed BroadcastEntitlementChange:57
     *  subscription.*  SubscriptionService / SubscriptionsTick::payload() (+ extras)
     *  app.uninstalled AppInstallService:200 (+ reason on account deletion)
     *  (+ restaurant_id: P1-04 legacy mirror of store_id, added by DeliverWebhookJob)
     *
     * @var array<string, array<int, string>>
     */
    private const REAL_DISPATCH_KEYS = [
        'order.created' => ['order_id', 'store_id', 'type', 'status', 'total', 'currency', 'track_token', 'source', 'table_number', 'hs_order_id', 'restaurant_id'],
        'order.status_changed' => ['order_id', 'new_status', 'by_staff'],
        'order.paid' => ['order_id', 'total', 'payment_gateway', 'via'],
        'refund.completed' => ['order_id', 'amount', 'method'],
        'reservation.created' => ['reservation_id', 'status', 'source'],
        'reservation.cancelled' => ['reservation_id', 'status', 'source'],
        'customer.created' => ['customer_id', 'name'],
        'shift.opened' => ['shift_id', 'branch_id', 'staff_id'],
        'shift.closed' => ['shift_id', 'over_short'],
        'menu.updated' => ['store_id', 'change', 'entity', 'id', 'restaurant_id'],
        'entitlement.changed' => ['action', 'feature_key', 'source_type', 'source_id', 'user_id', 'occurred_at'],
        'subscription.paused' => ['subscription_id', 'plan_id'],
        'subscription.resumed' => ['subscription_id', 'ends_at'],
        'subscription.renewed' => ['subscription_id', 'plan_id', 'ends_at'],
        'subscription.expired' => ['subscription_id', 'user_id', 'plan_id', 'status', 'ends_at', 'trial'],
        'subscription.plan_changed' => ['subscription_id', 'user_id', 'plan_id', 'status', 'ends_at', 'scheduled'],
        'subscription.past_due' => ['subscription_id', 'user_id', 'plan_id', 'status', 'ends_at'],
        'subscription.expiring' => ['subscription_id', 'user_id', 'plan_id', 'status', 'ends_at', 'days_left'],
        'subscription.trial_ending' => ['subscription_id', 'user_id', 'plan_id', 'status', 'ends_at', 'days_left'],
        'app.uninstalled' => ['app', 'user_id', 'uninstalled_at', 'reason'],
    ];

    /** Subscribable events that carry a payload (everything but the `*` wildcard). */
    private function events(): array
    {
        return array_values(array_filter(WebhookEndpoint::SELECTABLE_EVENTS, fn ($e) => $e !== '*'));
    }

    private function fixturePath(string $event): string
    {
        return base_path(self::DIR.'/'.$event.'.json');
    }

    /** @return array<string,mixed> */
    private function fixture(string $event): array
    {
        $decoded = json_decode((string) file_get_contents($this->fixturePath($event)), true);
        $this->assertIsArray($decoded, "fixture for {$event} is not valid JSON");

        return $decoded;
    }

    public function test_every_subscribable_event_has_a_fixture(): void
    {
        $missing = array_values(array_filter($this->events(), fn ($e) => ! is_file($this->fixturePath($e))));
        $this->assertSame([], $missing, "Subscribable events with no sample fixture:\n - ".implode("\n - ", $missing));
    }

    public function test_there_are_no_orphan_fixtures(): void
    {
        $files = glob(base_path(self::DIR.'/*.json')) ?: [];
        $known = $this->events();
        $orphans = [];
        foreach ($files as $f) {
            $event = basename($f, '.json');
            if (! in_array($event, $known, true)) {
                $orphans[] = $event;
            }
        }
        $this->assertSame([], $orphans, "Fixtures for events that are not subscribable:\n - ".implode("\n - ", $orphans));
    }

    public function test_each_fixture_is_a_valid_delivery_envelope(): void
    {
        foreach ($this->events() as $event) {
            $f = $this->fixture($event);

            $this->assertArrayHasKey('id', $f, "{$event}: envelope missing top-level id");
            $this->assertStringStartsWith('msg_', (string) $f['id'], "{$event}: id must be a msg_ message id");
            $this->assertSame($event, $f['event'] ?? null, "{$event}: envelope event field must match the filename");
            $this->assertArrayHasKey('created_at', $f, "{$event}: envelope missing created_at");
            $this->assertIsArray($f['data'] ?? null, "{$event}: envelope data must be an object");
            $this->assertNotEmpty($f['data'], "{$event}: data must not be empty");
        }
    }

    public function test_fixture_data_keys_are_a_subset_of_real_dispatch_keys(): void
    {
        foreach ($this->events() as $event) {
            $this->assertArrayHasKey($event, self::REAL_DISPATCH_KEYS, "No dispatch-key allowlist for {$event} — add it when the event's dispatch changes.");

            $extra = array_diff(array_keys($this->fixture($event)['data']), self::REAL_DISPATCH_KEYS[$event]);
            $this->assertSame([], $extra, "{$event}: sample invents key(s) no live dispatcher sends: ".implode(', ', $extra));
        }
    }

    public function test_date_like_values_are_iso8601_with_timezone(): void
    {
        $iso = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}([+-]\d{2}:\d{2}|Z)$/';

        foreach ($this->events() as $event) {
            $f = $this->fixture($event);
            $this->assertMatchesRegularExpression($iso, (string) $f['created_at'], "{$event}: created_at is not ISO-8601 with a timezone");

            foreach ($f['data'] as $key => $value) {
                if (is_string($value) && (str_ends_with($key, '_at') || $key === 'occurred_at')) {
                    $this->assertMatchesRegularExpression($iso, $value, "{$event}: data.{$key} is not ISO-8601 with a timezone");
                }
            }
        }
    }
}
