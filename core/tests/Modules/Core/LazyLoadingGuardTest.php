<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * P1-17: in local and testing a lazy relationship load throws, so an N+1 fails the test that causes it.
 */
class LazyLoadingGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_lazy_loading_is_prevented_in_the_testing_environment(): void
    {
        $this->assertTrue(Model::preventsLazyLoading());
    }

    public function test_touching_an_unloaded_relation_on_a_stored_model_throws(): void
    {
        User::factory()->count(2)->create();
        $user = User::query()->get()->first();

        $this->expectException(LazyLoadingViolationException::class);
        $user->getRelationValue('stores');
    }
}
