# Explicit sort keys for named grids

Naming a grid does not automatically give it an independent sort key. Configure
its existing model API **before creating sortable columns**:

```php
$grid->setName('orders')->paginate(5);
$grid->model()->setSortName('orders_sort');
$grid->column('id')->sortable();
$grid->column('rank')->sortable();
```

Configure another grid similarly with its own name and sort key. Sortable columns
capture the model's sort key when `sortable()` creates their sorter. This fixture
does not promise that changing the key afterward updates existing columns.
The default shared `_sort` behavior and first-click descending direction remain
unchanged. This change adds regression coverage, not a production sorting fix.

`GridExplicitSortTest.php` uses fresh Testbench applications and SQLite in-memory
databases. Eighty deterministic rows have unique inverse ID/rank values and
alternating statuses. Both grids execute actual named equality and nested range
filters. The ranges exclude otherwise matching rows, so totals and exact IDs
check both kinds of filter rather than merely retaining query text.

The tests follow production-rendered column links through the HTTP kernel to
check ascending/descending toggles, switching columns, and independent sorting
of the second grid. They also follow a next-page link present in rendered
pagination HTML, a rendered page-size option, and the rendered filter reset link.
Exact query comparisons retain both explicit sort arrays, the other grid's
page/page size and filters, and an unrelated nested context value. Exact row
assertions check the resulting queries. Reset removes only the selected grid's
filters and page, retaining its sort and page size and the other grid's state.
The fixture restores the script buffer, column attributes/model cache and grid
attribute-naming cache it touches, preserving pre-test values on setup failure
and teardown. It does not introduce a production state-reset mechanism.

Run this case using the existing integration consumer setup:

```sh
composer test -- --filter GridExplicitSortTest
```

The existing [pagination regression](GRID_PAGINATION.md) independently covers
the default shared sort parameter. These are HTTP-kernel, SQLite, filter and
rendered-link checks. They do not run browser JavaScript, PJAX transport, a full
admin page, relation/casted-column sorting, arbitrary custom filters, invalid sort
inputs, other databases, or every configuration order. Passing them does not
establish universal multi-grid or framework compatibility.
