<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

/**
 * The kit demo stores (KitDemoSeeder, ThemeShowcaseSeeder) keep opening hours (07:00-23:00 store time): a test that renders the
 * "open" state or places an order fails at night ("This store is currently closed"). Use this trait in such a test: the clock is
 * pinned to 12:00 Riyadh (09:00 UTC) before every test (Laravel calls setUp<TraitName> of every used trait) and freed after it.
 */
trait PinsMiddayClock
{
    protected function setUpPinsMiddayClock(): void
    {
        $this->travelTo(now('UTC')->setTime(9, 0));
    }
}
