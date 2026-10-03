# List field bounds

`$form->list('items')->min(2)->max(3)` validates the submitted list size even
without item `rules()`. Item rules still apply independently to each value;
custom `validator()` callbacks retain precedence. The default unbounded list
(minimum zero, no maximum, no item rules) keeps its validation fast path.

The real Laravel HTTP regression checks rendered controls, rejected creates
without insertion, rejected updates without changing the record, valid bound
endpoints, and omitted-field updates. Field-level cases cover min-only,
max-only, zero bounds, an explicit empty array, rule callbacks, creation/update
rules and validation messages.

An omitted field retains the existing skip behavior. Explicitly removing all
rows submits an empty marker as described below. These tests use the
in-process HTTP kernel and SQLite, not browser JavaScript.

## Scoped list input names

Existing rows and row templates use the field's scoped HTML element name.
Embedded lists therefore submit `settings[items][values][]`, and HasMany rows
submit `children[record-id][items][values][]`. Top-level names remain
`items[values][]`. Old input and displayed errors use the matching scoped error
key, so an unrelated validation failure retains each submitted list separately.

`ListFieldScopedNameTest` checks escaped names/values/errors, native PHP form
parsing of rendered controls, embedded and two-record HasMany update round trips,
and validation redisplay without writes. This is rendered-HTML and HTTP-kernel
coverage, not browser JavaScript. In particular, the pre-existing column-global
add/template selectors and nested validator aggregation are unchanged. The scoped-name fix is also used by the empty markers below.


## Explicitly empty collections

ListField and KeyValue render hidden empty scalar defaults outside removable rows,
before the table. Later row controls replace these scalars with arrays through
PHP's native form parsing. With no rows left, only these explicit defaults remain;
Laravel may convert their empty strings to null. Field preparation normalizes only
those exact null/empty-string envelope parts to empty arrays. Real blank, null,
and zero-valued rows are preserved. Missing fields still preserve stored values.

ListField's local validator treats the marker as a zero-length array: `min(0)`
allows clearing and a positive minimum rejects it without changing the record.
Custom field validators retain precedence and receive the original input, as do
raw-input hooks; consumers of those hooks must account for the scalar marker.
Old explicit empty input redisplays zero rows after validation failure, including
scoped fields. KeyValue's existing per-item/distinct rules and duplicate/blank-key
preparation semantics remain unchanged; it has no collection minimum API.

`ExplicitEmptyCollectionsTest` uses real rendered controls, native PHP parsing,
the HTTP kernel, and SQLite. DOM removal simulates removal of row controls; no
JavaScript is executed. Column-global add/template selectors and nested validator
aggregation remain separate pre-existing limitations, so these tests do not claim
repeated-instance JavaScript correctness or nested list-bound validation.

Collection `disable()`/`readonly()` attributes remain unsupported by these
existing templates. Code that deliberately omits a field by disabling its
controls must disable the hidden markers too (for example, via a disabled
ancestor fieldset). The package's hidden-cascade submission handler already
disables all `:input` controls in the hidden group, including the markers.
