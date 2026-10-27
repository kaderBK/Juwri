<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Mut;

use Illuminate\Http\Request;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\GatewayAdapter;
use Symfony\Component\HttpFoundation\Response;

/** P6-28: a refund adapter whose answer each test sets; records every call. */
class FakeRefundAdapter implements GatewayAdapter
{
    /** @var array<string,mixed>|\Throwable */
    public static array|\Throwable $result = ['ok' => true, 'reference' => 'ref-1'];

    /** @var list<array{int,float,?string}> */
    public static array $calls = [];

    public static bool $refunds = true;

    public static function reset(): void
    {
        self::$result = ['ok' => true, 'reference' => 'ref-1'];
        self::$calls = [];
        self::$refunds = true;
    }

    public function key(): string
    {
        return 'my_pay';
    }

    public function initiate(Transaction $transaction): Response
    {
        return new Response('');
    }

    public function handleReturn(Request $request): Response
    {
        return new Response('');
    }

    public function handleWebhook(Request $request): Response
    {
        return new Response('');
    }

    public function supportsRefund(): bool
    {
        return self::$refunds;
    }

    public function refund(Transaction $transaction, float $amount, ?string $currency = null): array
    {
        self::$calls[] = [(int) $transaction->getKey(), $amount, $currency];
        if (self::$result instanceof \Throwable) {
            throw self::$result;
        }

        return self::$result;
    }
}
