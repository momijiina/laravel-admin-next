# Nullable Grid carousel images

`$grid->column('images')->carousel()` now displays an empty cell when its value
is `null`, as it already does for an empty array. This includes an ordinary
nullable SQL column with Eloquent's `array` cast: SQL `NULL` remains `null` rather
than becoming `[]`. Previously, `array_values(null)` threw a `TypeError` and
prevented the whole Grid from rendering. The change only affects presentation;
it does not change stored data or add a default value to the model.

Non-null behavior is unchanged. Arrays and `Arrayable` values keep their existing
conversion, insertion order and numeric reindexing. Existing falsey-member
filtering still happens after reindexing; it does not renumber surviving items
again. An initially nonempty array with only falsey members still produces an
empty carousel widget. This patch deliberately does not broaden support to
strings, booleans, numbers or arbitrary objects.

`GridCarouselTest.php` renders the real Grid through Laravel's in-process HTTP
kernel with a fresh SQLite database. It covers SQL `NULL`, `[]`, populated,
sparse and associative array casts, and a mixed page where a null row must not
hide the populated row. Controls check image order, indicators, active item,
row-specific IDs, navigation targets, default/custom dimensions, custom-server
joining, configured storage URLs, absolute URLs and existing data-image URL
normalization. The view still passes every image through Laravel's `url()`;
on the tested frameworks that prefixes a data-image string with the application
URL. This existing behavior is not corrected by the null-handling patch. Direct
displayer checks additionally cover empty/populated Laravel collections,
existing falsey filtering, exact empty output and unsupported non-null values.
The fixture restores the package static state it touches after setup failure or
teardown, retaining the pre-test values.

Run using the [integration consumer setup](README.md):

```sh
composer test -- --filter GridCarouselTest
```

No view or asset changes are required. Custom displayers replacing the built-in
carousel need their own null handling. These checks do not exercise a browser,
image fetching, animation/layout, live PJAX, arbitrary custom casts or overrides,
non-SQLite databases, or all admitted PHP/framework versions. Passing rendered
HTML checks does not establish browser end-to-end behavior or full compatibility.
