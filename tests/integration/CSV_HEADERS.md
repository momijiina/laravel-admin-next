# CSV headers for empty results

Empty CSV exports now contain the UTF-8 BOM and one header record, rather than
only the BOM. This applies to empty tables, filters matching no rows, selected
IDs matching no rows, and current-page exports on page 1 of an empty dataset.
Consumers that previously treated a BOM-only file as the empty-result signal
must instead check for zero data records after the header.

The header follows existing grid visibility, request `_columns_`, exporter
`only()` / `except()`, and `title()` callback rules. Column order stays in grid
order. Empty `only([])` / `except([])` retain their legacy no-restriction meaning;
hiding every grid column retains the existing fallback to all configured
columns. A selection with no matching columns (or no configured columns) writes
one blank header record. Nonempty zero-column exports also write that header
only once across chunks, followed by one blank record per matching row.

Export configuration callbacks still run before fetching rows. Nonempty display
callbacks still run before the first title callback; empty results do not run
row display or exporter column callbacks. Title callbacks now run once for an
empty result when their column is visible to the grid, even if exporter
`only()` / `except()` later excludes it, matching the nonempty API order.
Existing delimiters, enclosure, escape, BOM, queries and scope handling are
unchanged. `title()` callback results such as `"0"` and the empty string are
retained; ordinary grid-label normalization is unchanged.

`CsvHeadersTest.php` executes the actual HTTP kernel and exporter stream/exit
in child processes with in-memory SQLite. CSV parse-back covers named/unnamed
filters and sorts, all/current-page/selected scopes, customization, Unicode,
commas, quotes, embedded newlines, zero cells, and 106 rows across the 100-row
chunk boundary. Callback and query traces verify timing and one header.
Subprocess stderr must be empty and runtime diagnostics are promoted to failures.
The fixture explicitly selects `new CsvExporter()` through the supported
`Grid::exporter()` API; the legacy null-driver resolver is outside this test's
strict diagnostic coverage. No dependency constraints are changed.

These checks do not certify live browser downloads, spreadsheet applications,
non-SQLite databases, stale/out-of-range pages, or arbitrary consumer exporters.
