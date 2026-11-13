<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
| Timezone choices for the platform settings screen: identifier => label.
|
| Built from PHP's tz database: every canonical zone plus the legacy
| "Region/City" aliases (e.g. US/Eastern, Canada/Pacific) so a value saved by
| an older install still validates. Labels show the standard (non-DST) UTC
| offset and the list is ordered by that offset, then by name.
*/

$year = (int) gmdate('Y');
$january = new DateTimeImmutable("{$year}-01-01 12:00:00", new DateTimeZone('UTC'));
$july = new DateTimeImmutable("{$year}-07-01 12:00:00", new DateTimeZone('UTC'));

$ids = array_unique(array_merge(
    DateTimeZone::listIdentifiers(),
    array_filter(
        DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC),
        static fn (string $id): bool => str_contains($id, '/') && ! str_starts_with($id, 'Etc/'),
    ),
));

$zones = [];
foreach ($ids as $id) {
    $zone = new DateTimeZone($id);
    // Daylight saving only ever moves clocks forward, so the smaller of the
    // two mid-season offsets is the zone's standard offset.
    $zones[$id] = min($zone->getOffset($january), $zone->getOffset($july));
}

uksort($zones, static fn (string $a, string $b): int => [$zones[$a], $a] <=> [$zones[$b], $b]);

$labels = [];
foreach ($zones as $id => $offset) {
    $sign = $offset < 0 ? '-' : '+';
    $offset = abs($offset);
    $labels[$id] = sprintf('(GMT%s%02d:%02d) %s', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60), $id);
}

return $labels;
