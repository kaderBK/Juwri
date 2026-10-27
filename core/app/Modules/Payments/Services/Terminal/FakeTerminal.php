<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Terminal;

use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\PaymentTerminal;

/**
 * Test double of a SoftPOS provider with scripted deferred authorisation: script() sets what the provider answers for a
 * reference (pending until forwarded, then authorised / captured / declined). Never used outside tests and demos.
 */
final class FakeTerminal implements PaymentTerminal
{
    /** @var array<string, array{state: string, masked_pan?: string, scheme?: string, auth_code?: string}> */
    private array $answers = [];

    /** @var list<string> */
    public array $asked = [];

    public function __construct(private bool $offline = true) {}

    public function key(): string
    {
        return 'fake_terminal';
    }

    public function supportsOffline(Store $store): bool
    {
        return $this->offline;
    }

    /** @param  array{state: string, masked_pan?: string, scheme?: string, auth_code?: string}|string  $answer */
    public function script(string $reference, array|string $answer): void
    {
        $this->answers[$reference] = is_string($answer) ? ['state' => $answer] : $answer;
    }

    public function offlineStatus(Store $store, string $reference): array
    {
        $this->asked[] = $reference;

        return $this->answers[$reference] ?? ['state' => self::PENDING];
    }
}
