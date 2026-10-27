<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Limits;

/**
 * The answer of {@see StoreLimitRules}: allowed, or refused with a reason code, the limit and the count it was
 * compared with. {@see message()} is the translated text shown at the limit (never a price).
 */
final class LimitDecision
{
    public const STORES_PER_TYPE = 'stores_per_type';

    public const BRANCHES_PER_STORE = 'branches_per_store';

    public const BRANCHES_TOTAL = 'branches_total';

    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly ?int $limit,
        public readonly int $used,
        public readonly string $edition,
    ) {}

    public static function allow(string $edition): self
    {
        return new self(true, null, null, 0, $edition);
    }

    public static function refuse(string $edition, string $reason, int $limit, int $used): self
    {
        return new self(false, $reason, $limit, $used, $edition);
    }

    public function refused(): bool
    {
        return ! $this->allowed;
    }

    /** What the owner sees at the limit: points to the Chain licence / juwri Cloud; never a price. */
    public function message(): string
    {
        if ($this->allowed) {
            return '';
        }

        return (string) match (true) {
            $this->reason === self::STORES_PER_TYPE => ___('The Community Edition includes one store of each store type. To add another store of this type, use the juwri Chain licence or juwri Cloud.'),
            $this->reason === self::BRANCHES_PER_STORE => ___('The Community Edition includes up to :limit branches per store. To add more branches, use the juwri Chain licence or juwri Cloud.', ['limit' => $this->limit]),
            $this->edition === 'chain' => ___('Your Chain licence covers up to :limit branches in total. To add more branches, extend your Chain licence or use juwri Cloud.', ['limit' => $this->limit]),
            default => ___('Your juwri licence covers up to :limit branches in total. To add more branches, extend your licence.', ['limit' => $this->limit]),
        };
    }

    /** @return array{allowed: bool, reason: string|null, limit: int|null, used: int, edition: string} */
    public function toArray(): array
    {
        return ['allowed' => $this->allowed, 'reason' => $this->reason, 'limit' => $this->limit, 'used' => $this->used, 'edition' => $this->edition];
    }
}
