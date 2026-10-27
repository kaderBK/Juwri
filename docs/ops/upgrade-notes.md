# Upgrade notes

## Item search column (P6-295)

`php artisan migrate` adds `items.name_search` and backfills it with the normalised item name (Arabic spelling variants match one item). On a very large catalogue on shared hosting the migration can be cut off by the host's time limit. Re-running `php artisan migrate` is safe, and the backfill can also be finished outside the request:

    php artisan juwri:items:backfill-name-search

It only touches rows whose `name_search` is empty and can be run any number of times.
