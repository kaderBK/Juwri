<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

/**
 * The plan stand-in Community tests get from {@see TestPlans::create()} when no edition sells plans: the edition plan id
 * ('free' — the `users.group_id` default) and the attributes the test passed, readable as properties. It is not a model;
 * a test that needs persisted plans, limits or entitlements belongs to the edition package's tests.
 */
final class CommunityTestPlan
{
    public string $id = 'free';

    public object $settings;

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            if ($key !== 'id' && $key !== 'settings') {
                $this->{$key} = $value;
            }
        }
        $settings = $attributes['settings'] ?? [];
        $this->settings = (object) (is_string($settings) ? (json_decode($settings, true) ?: []) : (array) $settings);
    }

    public function fresh(): self
    {
        return $this;
    }

    public function refresh(): self
    {
        return $this;
    }
}
