# Inclusive Grid inequality filters

The existing ordinary Grid `gt()` and `lt()` methods execute `>=` and `<=` SQL,
respectively. They include the boundary value, including zero and negative
numbers. This longstanding query behavior and both method names are unchanged.
The shipped filter labels now display `(>=)` and `(<=)` to match the queries;
the English and Chinese guide headings and SQL examples are corrected too.

## Upgrade caution

Applications with published or overridden `filter/gt.blade.php` or
`filter/lt.blade.php` views may still display the old strict `(>)` / `(<)` labels.
Update those labels where the filters use the standard inclusive conditions.
Review custom conditions and custom labels together: this change does not alter
custom filter implementations or introduce a strict-comparison API.

## Regression coverage

`GridInequalityFilterTest.php` boots the package provider and real Laravel HTTP
kernel with a fresh SQLite in-memory table containing `[-2, -1, 0, 1, 2]`. It
submits the production-rendered GET forms for each boundary and then blank,
checking exact returned rows and totals, parameterized SQL and exact bindings,
redisplayed values, and inclusive labels in English and Chinese locales. In
particular, `gt('quantity')` with `0` returns `[0, 1, 2]`, and `lt('quantity')`
with `0` returns `[-2, -1, 0]`. Every nonblank boundary includes equality.
Missing or blank input omits the condition and returns all five rows.

Each rendered reset link is followed through HTTP and checked for cleared input,
no SQL condition or bindings, and all rows restored. The fixture restores the
package static values it touches on setup failure and teardown. No production
query, request parsing, reset behavior or method name changes are needed.

Run using the existing integration consumer setup:

```sh
composer test -- --filter GridInequalityFilterTest
```

These are ordinary single-column HTTP-kernel/SQLite and rendered-HTML checks.
They do not execute browser JavaScript, test relation/grouped/custom filters,
exercise other databases, or establish complete framework support. `Between`
is outside this change. Strict-SQL and old-label negative controls should fail
the same regression independently.
