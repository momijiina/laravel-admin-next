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

An omitted field retains the existing skip behavior. In particular, removing
all dynamic rows in the browser does not submit an explicit empty array; this
change does not change that separate frontend behavior. These tests use the
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
add/template selectors and nested validator aggregation are unchanged. The fix
adds no empty markers or clear-all behavior.
