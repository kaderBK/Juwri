<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Hotel\Services\Hotel\Pms;

use Juwri\Core\Models\Credential;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Hotel\Models\HotelStay;
use App\Models\Order;
use Juwri\Modules\Hotel\Services\Hotel\HotelFolioService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * FEATURES-RUN-4 T1 / H10 — the Mews Connector API PMS driver (A8).
 *
 * Implements the POS "post charge items to a customer profile / reservation"
 * use-case (docs.mews.com/connector-api/use-cases/point-of-sale): each
 * charge-to-room order is mirrored onto the guest's Mews bill so it settles at
 * PMS checkout. The LOCAL unified-ledger folio ({@see HotelFolioService}) stays
 * authoritative — this mirror is BEST-EFFORT: a Mews outage (timeout, 4xx/5xx)
 * is logged and swallowed, never failing an in-house order.
 *
 * Config lives in a per-restaurant {@see Credential} row (feature='pms',
 * provider='mews'); `secrets` carries base_url, client_token, access_token and
 * service_id. {@see PmsManager::for()} selects this driver when that row's
 * provider column is 'mews'.
 *
 * NO live HTTP ships or runs in tests/CI — every request goes through the Laravel
 * Http client so Http::fake fixtures define the contract.
 */
class MewsConnector implements PmsConnectorInterface
{
    /** Mews Connector API path for posting POS order items onto a bill. */
    private const ORDERS_ADD_PATH = '/api/connector/v1/orders/add';

    /** A short, defensive HTTP budget — the mirror must never stall an order. */
    private const TIMEOUT_SECONDS = 5;

    public function key(): string
    {
        return 'mews';
    }

    public function label(): string
    {
        return 'Mews';
    }

    /**
     * Guest validation against the Mews in-house list is not wired in v1 (the
     * interface has no restaurant context to resolve credentials from). Local
     * HotelFolioService::guestNameMatches remains the source of truth — return null.
     */
    public function validateGuest(string $room, string $name): ?GuestDto
    {
        return null;
    }

    /**
     * Post the charge-to-room order onto the guest's Mews bill. Best-effort: an
     * incomplete config or any HTTP failure is logged and returns quietly.
     */
    public function postCharge(HotelStay $stay, Order $order): void
    {
        $config = Credential::for(PmsManager::FEATURE, (int) $stay->store_id) ?: [];
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $clientToken = (string) ($config['client_token'] ?? '');
        $accessToken = (string) ($config['access_token'] ?? '');

        if ($baseUrl === '' || $clientToken === '' || $accessToken === '') {
            Log::warning('Mews postCharge skipped: incomplete credentials', [
                'stay' => $stay->id, 'order' => $order->id,
            ]);

            return;
        }

        // P6-68: the base URL is owner-supplied and receives guest data + tokens —
        // https + public host only, pinned to the vetted IP (DNS rebinding), no redirects.
        $endpoint = $baseUrl.self::ORDERS_ADD_PATH;
        $guard = SsrfGuard::pinnedOptions($endpoint, false);
        if ($guard === null) {
            Log::warning('Mews postCharge blocked: base URL is not a safe public https endpoint', [
                'stay' => $stay->id, 'order' => $order->id,
            ]);

            return;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withOptions($guard)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, $this->chargePayload($stay, $order, $config));

            if ($response->failed()) {
                Log::warning('Mews postCharge HTTP error', [
                    'stay' => $stay->id, 'order' => $order->id, 'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Mews postCharge exception', [
                'stay' => $stay->id, 'order' => $order->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fetch in-house stays for a future reconcile/import. Not wired in v1.
     *
     * @return iterable<GuestDto>
     */
    public function fetchStays(): iterable
    {
        return [];
    }

    /**
     * Build the Mews Connector API `orders/add` payload for one order — the POS
     * charge-posting shape (ClientToken/AccessToken auth, a ServiceId, and one
     * Order carrying the room-service charge as a single line item priced in the
     * store's currency). The fixtures in HotelPmsMewsTest pin this contract.
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function chargePayload(HotelStay $stay, Order $order, array $config): array
    {
        $currency = strtoupper((string) ($order->currency_code
            ?: HotelFolioService::currencyFor((int) $stay->store_id)));
        // Mirror what the local folio was charged (amount_due), rounded to the
        // currency's minor unit — Mews applies its own tenders on top.
        $amount = round((float) $order->amount_due, money_decimals($currency));

        return [
            'ClientToken' => (string) ($config['client_token'] ?? ''),
            'AccessToken' => (string) ($config['access_token'] ?? ''),
            'Client'      => 'juwri POS 1.0',
            'ServiceId'   => (string) ($config['service_id'] ?? ''),
            'Orders'      => [[
                'Identifier'        => 'order-'.$order->id,
                'CustomerReference' => (string) $stay->guest_name,
                'Notes'             => 'Room-service order #'.$order->id.' ('.((string) ($order->service_location ?: $stay->table_id)).')',
                'Items'             => [[
                    'Name'            => 'Room-service order #'.$order->id,
                    'UnitCount'       => 1,
                    'UnitAmount'      => [
                        'Currency'   => $currency,
                        'GrossValue' => $amount,
                    ],
                    'ConsumptionUtc'  => Carbon::now()->toIso8601String(),
                ]],
            ]],
        ];
    }
}
