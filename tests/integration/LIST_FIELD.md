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
