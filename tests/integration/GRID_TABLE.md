# Grid nested-table cell alignment

`$grid->column('lines')->table(['sku' => 'SKU', 'quantity' => 'Quantity',
'price' => 'Price'])` now emits one cell for every configured column in each
array row. A missing field becomes `null` in the data passed to the table view,
which the shipped view renders as an empty cell. For example, a row containing
only `sku` and `price` keeps its price under Price rather than shifting it under
Quantity. Missing first, middle, last and all fields retain their positions.

This is presentation-only: neither the model's original JSON nor stored values
are rewritten. Existing values, including explicit `null`, zero, `false` and
empty strings, retain their types in the view data. Configured column order,
excluded extra fields, literal keys (including dots), mapped labels, explicit
field-name lists and first-row header inference are preserved. The shipped
view continues to escape values. Empty top-level values still return an empty
string; unsupported nonarray rows or nonempty nonarray outer values are not
newly coerced or accepted.

## Custom views and scope

No asset or view refresh is required. An overridden `admin::grid.displayer.table`
view now receives every configured key in each row, with explicit `null` for an
absent key. Review custom code that distinguishes missing keys from null, counts
row entries, or relies on omission. Overrides that replace the displayer itself
need their own alignment handling.

Header inference still uses only the row at outer index `0`; this is not union
header inference. Sparse/non-zero-based outer arrays, object-row support,
`Widgets\Table`, storage formats and unrelated displayers are outside this fix.

## Verification

`GridTableTest.php` boots the actual provider and HTTP kernel, stores real
Eloquent array-cast values in SQLite, renders the ordinary Grid and shipped
nested-table view, and checks exact header/cell order and cell counts. A view
composer observes the actual passed arrays without replacing a class or view,
checking exact keys, order, nulls and value types. Each request verifies the
stored JSON and reloaded cast value are unchanged. Controls cover complete and
reordered rows, subset/custom order, excluded extras, falsey values, escaped
HTML, literal dotted/numeric keys, first-row-only inference and empty output.
Direct displayer checks preserve existing nonarray error contracts. Package
static state is restored after teardown or setup failure.

Run using the [integration consumer setup](README.md):

```sh
composer test -- --filter GridTableTest
```

These are in-process HTTP/SQLite and rendered HTML checks, not browser layout,
live PJAX/transport, arbitrary custom view/cast coverage, non-SQLite storage,
or proof of compatibility with every allowed PHP/framework version.
