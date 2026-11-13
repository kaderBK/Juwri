<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

/**
 * A source WITHOUT a wallet ledger table (base schema) keeps the owner's platform wallet only in `user.balance`. This
 * install reads a wallet balance from its ledger (the sum of `wallet_transactions`), never from `users.balance_minor`,
 * so a non-zero source balance becomes ONE opening-balance ledger row per user (marked `opening_balance`), in minor
 * units of the platform currency. With a source ledger nothing is written here: the ledger rows are imported and
 * reconciled ({@see LedgerChecks}).
 */
final class OpeningBalances
{
    public const MARK = 'opening_balance';

    public const REASON = 'legacy_opening_balance';

    public function __construct(private readonly ImportContext $ctx) {}

    public function write(): void
    {
        $source = $this->ctx->source;
        $currency = $this->ctx->platformCurrency;
        if ($currency === null || $source->has(LedgerChecks::WALLET) || ! in_array('balance', $source->columns(LegacyTableMaps::USERS), true)) {
            return;
        }
        $target = $this->ctx->target;
        $exponent = $this->ctx->platformExponent;
        $source->chunk(LegacyTableMaps::USERS, function (array $rows) use ($target, $currency, $exponent): void {
            $todo = [];
            foreach ($rows as $row) {
                $user = (int) $row['id'];
                $minor = LegacyMoney::toMinor($row['balance'] ?? null, $exponent);
                if ($minor !== null && $minor !== 0 && $this->ctx->resolves('users', $user)) {
                    $todo[$user] = $minor;
                }
            }
            if ($todo === []) {
                return;
            }
            $done = array_flip($target->table(RowLoader::SIDE_TABLE)->where('source_table', LegacyTableMaps::USERS)
                ->where('target_table', 'wallet_transactions')->whereIn('source_id', array_map('strval', array_keys($todo)))->pluck('source_id')->all());
            $target->transaction(function () use ($todo, $done, $target, $currency): void {
                $now = now()->utc()->format('Y-m-d H:i:s');
                foreach ($todo as $user => $minor) {
                    if (isset($done[(string) $user])) {
                        continue;
                    }
                    $id = (int) $target->table('wallet_transactions')->insertGetId([
                        'user_id' => $user,
                        'account_id' => $this->ctx->accounts[$user] ?? null,
                        'amount_minor' => $minor,
                        'currency_code' => $currency,
                        'balance_after_minor' => $minor,
                        'type' => $minor < 0 ? 'debit' : 'credit',
                        'reason' => self::REASON,
                        'ref' => 'legacy-opening-'.$user,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $target->table(RowLoader::SIDE_TABLE)->insert([
                        'source_table' => LegacyTableMaps::USERS, 'source_id' => (string) $user, 'target_table' => 'wallet_transactions',
                        'target_id' => (string) $id, 'source_checksum' => hash('sha256', 'opening-balance|'.$user.'|'.$minor),
                        'run_id' => $this->ctx->runId, 'created_at' => $now,
                    ]);
                    $this->ctx->mark('wallet_transactions', $id, self::MARK, ['user_id' => $user]);
                    $this->ctx->report->written('wallet_transactions');
                }
                $this->ctx->flushMarks();
            });
        });
    }
}
