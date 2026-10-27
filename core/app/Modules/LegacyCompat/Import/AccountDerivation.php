<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Support\Str;
use Juwri\Core\Services\Tenancy\AccountProvisioner;

/**
 * Plan §1.1 rule 3 / J29-1: one tenant account per store OWNER (a source user that owns at least one store); the
 * owner's stores are grouped under it. Derived, never guessed:
 *  - account id = the owner's user id (deterministic, so a resumed run finds the same account);
 *  - `users.account_id` of the owner = that account; users who own no store get none (as on a fresh install,
 *    AccountProvisioner only creates accounts for store owners);
 *  - a store whose owner is missing or was not imported is refused (reported), never attached to some account.
 */
final class AccountDerivation
{
    public function __construct(private readonly ImportContext $ctx, private readonly string $runId) {}

    /**
     * Owner user ids among $userIds (users that own at least one source store).
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public function owners(array $userIds, string $storeTable, string $ownerColumn): array
    {
        if ($userIds === [] || ! $this->ctx->source->has($storeTable)) {
            return [];
        }
        $column = $this->ctx->source->pick($storeTable, $ownerColumn);
        if ($column === null) {
            // No owner column: no account can be derived (every store is then refused and reported), never guessed.
            if ($this->ctx->report->conflictCount('accounts: the source store table has no owner column, no account derived') === 0) {
                $this->ctx->report->conflict('accounts: the source store table has no owner column, no account derived', $storeTable.'.'.$ownerColumn);
            }

            return [];
        }

        return array_values(array_unique(array_map('intval', $this->ctx->source->db->table($storeTable)
            ->whereIn($column, $userIds)->whereNotNull($column)->distinct()->pluck($column)->all())));
    }

    /**
     * Create the accounts of these owner rows (source user rows) before the users are inserted. Returns owner user
     * id => account id.
     *
     * @param  list<array<string, mixed>>  $ownerRows
     * @return array<int, int>
     */
    public function create(array $ownerRows, string $sourceTable): array
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        $region = AccountProvisioner::region(null);
        $rows = [];
        $records = [];
        $map = [];
        foreach ($ownerRows as $user) {
            $id = (int) $user['id'];
            $name = trim((string) (($user['name'] ?? '') ?: ($user['username'] ?? '') ?: ($user['email'] ?? '') ?: 'Account '.$id));
            $rows[] = [
                'id' => $id,
                'public_id' => (string) Str::uuid7(),
                'owner_user_id' => $id,
                'name' => mb_substr($name, 0, 255),
                'region' => $region,
                'status' => 'active',
                'created_at' => $this->ctx->utc($user['created_at'] ?? null, $this->ctx->source->isInstant(LegacyTableMaps::USERS, 'created_at')) ?? $now,
                'updated_at' => $now,
            ];
            $records[] = [
                'source_table' => $sourceTable,
                'source_id' => (string) $id,
                'target_table' => 'accounts',
                'target_id' => (string) $id,
                'source_checksum' => hash('sha256', 'account-of-owner|'.$id),
                'run_id' => $this->runId,
                'created_at' => $now,
            ];
            $map[$id] = $id;
        }
        foreach (array_chunk($rows, 200) as $batch) {
            $this->ctx->target->table('accounts')->insert($batch);
        }
        foreach (array_chunk($records, 200) as $batch) {
            $this->ctx->target->table(RowLoader::SIDE_TABLE)->insert($batch);
        }
        $this->ctx->report->written('accounts', count($rows));
        foreach ($map as $owner => $account) {
            $this->ctx->accounts[$owner] = $account;
        }

        return $map;
    }

    /** Re-read the accounts of a resumed run (owner user id => account id) from the target. */
    public function reload(): void
    {
        foreach ($this->ctx->target->table('accounts')->whereNotNull('owner_user_id')->get(['id', 'owner_user_id']) as $row) {
            $this->ctx->accounts[(int) $row->owner_user_id] = (int) $row->id;
        }
        foreach ($this->ctx->target->table(RowLoader::SIDE_TABLE)->whereIn('target_table', ['users', 'allergies'])->get(['target_table', 'target_id']) as $row) {
            $this->ctx->platformIds[(string) $row->target_table][(string) $row->target_id] = true;
        }
        // Billing payments of a user only: a store's order payments (store_id set) are never platform rows (J29).
        foreach ($this->ctx->target->table('transaction')->whereNull('store_id')->pluck('id') as $id) {
            $this->ctx->platformIds['transaction'][(string) $id] = true;
        }
    }
}
