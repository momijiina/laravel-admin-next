# Named grid pagination

`Grid::setName('orders')` uses `orders_page` for pagination, including when
`Grid::paginate(10)` or `Grid::model()->paginate(10, ['id'])` configures the
page size. Naming before or after pagination configuration has the same effect.
The existing `orders_per_page` query override changes only that grid's size.

An explicitly supplied third Eloquent `paginate` argument remains authoritative,
even when it is the ordinary `page` name. Columns, explicit current-page values,
and the optional total value/resolver are retained. Unnamed grids continue to use
`page` and `per_page`; their default grid page size is 20.

`GridPaginationTest.php` runs real HTTP requests against SQLite. It checks exact
row IDs, independent grid navigation, generated HTML links and their follow-up
GETs, per-page overrides, both naming orders, selected columns, explicit page
names/pages, and numeric/callback totals. Links retain the other grid's page,
filter values and sort query. The default shared `_sort` behavior is unchanged;
callers can use the existing `Grid::model()->setSortName()` API where independent
sort parameters are desired. Configure that key before creating sortable columns;
see the separate [explicit sorting and applied-filter regressions](GRID_EXPLICIT_SORT.md).

This is HTTP-kernel/paginator coverage, not live-browser or PJAX transport
coverage. Filter query retention is tested; this fixture does not apply filters.
The numeric/callback total variants are exercised on Laravel 12 and 13.
