<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\BranchDeletion;
use Juwri\Core\Services\Tenancy\BranchMove;
use Tests\TestCase;

/** P6-341: every branch_id table has exactly one move rule, and an unclassified table blocks the move. */
class BranchMoveClassificationTest extends TestCase
{
    use RefreshDatabase;

    private ?string $probeDir = null;

    protected function tearDown(): void
    {
        EditionPackages::fake(null);
        if ($this->probeDir !== null) {
            @unlink($this->probeDir.'/tenant_tables.php');
            @rmdir($this->probeDir);
            $this->probeDir = null;
        }
        parent::tearDown();
    }

    public function test_every_branch_table_has_a_move_rule(): void
    {
        $unclassified = BranchMove::unclassified();
        $this->assertSame([], $unclassified, 'Tenant tables with branch_id but no BranchMove rule: '
            .implode(', ', $unclassified).'. Add each to BranchMove::blockHistory(), BLOCK_LINKED, drop(), STAY or rekey() '
            .'(or to BranchDeletion::HISTORY, PER_BRANCH, KEEP or MOVE).');
    }

    public function test_move_lists_do_not_overlap(): void
    {
        $lists = [
            'blockHistory()' => BranchMove::blockHistory(),
            'BLOCK_LINKED' => BranchMove::BLOCK_LINKED,
            'drop()' => BranchMove::drop(),
            'STAY' => BranchMove::STAY,
            'rekey()' => BranchMove::rekey(),
        ];
        $names = array_keys($lists);
        for ($i = 0; $i < count($names); $i++) {
            for ($j = $i + 1; $j < count($names); $j++) {
                $this->assertSame(
                    [],
                    array_values(array_intersect($lists[$names[$i]], $lists[$names[$j]])),
                    "{$names[$i]} and {$names[$j]} share tables",
                );
            }
        }

        $moveUnion = array_values(array_unique(array_merge(...array_values($lists))));
        $deletionUnion = array_values(array_unique([
            ...BranchDeletion::HISTORY, ...BranchDeletion::PER_BRANCH, ...BranchDeletion::KEEP, ...BranchDeletion::MOVE,
            ...BranchMove::EXTRA_HISTORY, ...BranchMove::EXTRA_DROP,
        ]));
        sort($moveUnion);
        sort($deletionUnion);
        $this->assertSame(
            $deletionUnion,
            $moveUnion,
            'a table is classified in only one of BranchMove / BranchDeletion (move-only: '
            .implode(', ', array_diff($moveUnion, $deletionUnion)).'; deletion-only: '
            .implode(', ', array_diff($deletionUnion, $moveUnion)).')',
        );
    }

    public function test_fiscal_and_order_tables_never_rekey(): void
    {
        $blocked = BranchMove::blockHistory();
        $rekeyed = BranchMove::rekey();
        foreach (['orders', 'fiscal_documents', 'fiscal_devices', 'pos_shifts', 'accounting_journals'] as $table) {
            $this->assertContains($table, $blocked, "$table must be in BranchMove::blockHistory()");
            $this->assertNotContains($table, $rekeyed, "$table must never be re-keyed");
        }
    }

    public function test_an_unclassified_table_blocks_the_move(): void
    {
        Schema::create('zz_p6341_probe', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
        });

        $source = Store::factory()->create();
        $target = Store::factory()->create();
        $branch = Branch::where('store_id', $source->id)->firstOrFail();

        $this->registerProbeTable();

        try {
            $this->assertContains('zz_p6341_probe', BranchDeletion::branchTables());
            $this->assertSame('unclassified', BranchMove::blocker($branch, $target));
        } finally {
            Schema::dropIfExists('zz_p6341_probe');
        }
    }

    private function registerProbeTable(): void
    {
        $this->probeDir = sys_get_temp_dir().'/juwri-p6341-probe-'.bin2hex(random_bytes(4));
        mkdir($this->probeDir);
        file_put_contents($this->probeDir.'/tenant_tables.php', "<?php\n\nreturn [\n"
            ."    'tables' => [\n"
            ."        'zz_p6341_probe' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],\n"
            ."    ],\n"
            ."    'parents' => [],\n"
            ."    'user_owned' => [],\n"
            ."];\n");

        $packages = EditionPackages::all();
        $packages[] = ['name' => 'tests/p6341-probe', 'path' => $this->probeDir, 'manifest' => ['tenant_tables' => $this->probeDir.'/tenant_tables.php']];
        EditionPackages::fake($packages);
    }
}
